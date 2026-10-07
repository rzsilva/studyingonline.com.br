<?php

declare(strict_types=1);

namespace App\Domain\Adaline;

use App\Domain\Auth\AuthUser;
use App\Integrations\Pagamento\BoletoCloudGateway;
use App\Integrations\Pagamento\ContaGateway;
use App\Integrations\Pagamento\GatewayException;
use App\Integrations\Pagamento\Titulo;
use App\Support\ApiException;
use App\Support\Audit;
use App\Support\Connection;
use Psr\Log\LoggerInterface;

/**
 * Faturamento da Adaline às instituições (tabelas AdalineCobranca, AdalineBoletoCloudExtrato,
 * AdalineConfiguracao do legado).
 *
 * Correções em relação ao legado:
 *  - CriarBoletoCobranca e UpdateCreditSistema eram rotas GET públicas (a segunda "protegida" por um
 *    token fixo no código) e as chaves de produção do BoletoCloud estavam no código-fonte;
 *    aqui só operadores da Adaline (ADALINE_OPERADORES + MASTER) e credenciais no .env;
 *  - o contador de documento fazia `BoletoNumDoc += BoletoNumDoc + 1` (dobrava a cada boleto);
 *  - o extrato de boletos nunca era gravado pelo sistema: agora cada boleto emitido pela escola
 *    no BoletoCloud entra no extrato (quando a instituição tem COBRAR_BOLETOS).
 */
final class AdalineService
{
    public const ABERTO = 'Em Aberto';
    public const PAGO = 'Pago';
    public const CANCELADO = 'Cancelado';

    /** @param string[] $operadores e-mails dos operadores da Adaline */
    public function __construct(
        private readonly Connection $db,
        private readonly BoletoCloudGateway $boletoCloud,
        private readonly Audit $audit,
        private readonly LoggerInterface $logger,
        private readonly array $operadores,
        private readonly string $contaToken,
        private readonly float $tarifaBoleto,
    ) {
    }

    /* ======================== ACESSO ======================== */

    public function ehOperador(AuthUser $u): bool
    {
        if (!$u->master || !$this->operadores) {
            return false;
        }
        $email = (string) $this->db->run('SELECT EMAIL FROM USUARIO WHERE ID = ?', [$u->id])->fetchColumn();
        return in_array(mb_strtolower($email), $this->operadores, true);
    }

    public function exigirOperador(AuthUser $u): void
    {
        if (!$this->ehOperador($u)) {
            throw ApiException::forbidden('Área exclusiva da Adaline.');
        }
    }

    /* =================== PAINEL DA ADALINE =================== */

    /** Instituições com plano, vencimento e contagem de alunos. */
    public function instituicoes(AuthUser $op): array
    {
        $this->exigirOperador($op);
        $rows = $this->db->run(
            "SELECT i.ID, i.FANTASIA, i.RAZAO_SOCIAL, i.CNPJ, i.EMAIL_COBRANCA, i.ATIVO, i.PLANO, i.ALUNOS_QTD_MAX, i.DATA_VENCIMENTO,
                    i.COBRAR_BOLETOS, i.URL,
                    (SELECT COUNT(*) FROM USUARIO u WHERE u.INSTITUICAO_ID = i.ID AND u.LISTA_PERFIL_ID = 3 AND u.INATIVO = 0) AS ATIVOS,
                    (SELECT COUNT(*) FROM USUARIO u WHERE u.INSTITUICAO_ID = i.ID AND u.LISTA_PERFIL_ID = 3 AND u.INATIVO = 1) AS INATIVOS,
                    (SELECT COUNT(*) FROM AdalineCobranca f WHERE f.FkInstituicaoId = i.ID AND f.FkSituacao = 'Em Aberto') AS FATURAS_ABERTAS
               FROM INSTITUICAO i ORDER BY i.ATIVO DESC, i.FANTASIA"
        )->fetchAll();
        return array_map(static fn ($r) => [
            'id' => (int) $r['ID'], 'nome' => $r['FANTASIA'], 'razaoSocial' => $r['RAZAO_SOCIAL'], 'cnpj' => $r['CNPJ'],
            'emailCobranca' => $r['EMAIL_COBRANCA'], 'ativo' => (bool) $r['ATIVO'], 'plano' => $r['PLANO'] !== null ? (int) $r['PLANO'] : null,
            'alunosQtdMax' => $r['ALUNOS_QTD_MAX'] !== null ? (int) $r['ALUNOS_QTD_MAX'] : null, 'vencimento' => $r['DATA_VENCIMENTO'],
            'cobrarBoletos' => (bool) $r['COBRAR_BOLETOS'], 'url' => $r['URL'],
            'ativos' => (int) $r['ATIVOS'], 'inativos' => (int) $r['INATIVOS'], 'faturasAbertas' => (int) $r['FATURAS_ABERTAS'],
        ], $rows);
    }

    /** Dados do contrato da instituição, editáveis só pela Adaline. */
    public function atualizarContrato(AuthUser $op, int $instituicaoId, array $in): void
    {
        $this->exigirOperador($op);
        $sets = [];
        $vals = [];
        $map = ['ativo' => 'ATIVO', 'plano' => 'PLANO', 'alunosQtdMax' => 'ALUNOS_QTD_MAX', 'cobrarBoletos' => 'COBRAR_BOLETOS',
            'vencimento' => 'DATA_VENCIMENTO', 'emailCobranca' => 'EMAIL_COBRANCA'];
        foreach ($map as $k => $col) {
            if (!array_key_exists($k, $in)) {
                continue;
            }
            $v = $in[$k];
            if (in_array($k, ['ativo', 'cobrarBoletos'], true)) {
                $v = filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            } elseif (in_array($k, ['plano', 'alunosQtdMax'], true)) {
                $v = $v === null || $v === '' ? null : max(0, (int) $v);
            } elseif ($k === 'vencimento') {
                if ($v !== null && $v !== '' && !\DateTimeImmutable::createFromFormat('!Y-m-d', (string) $v)) {
                    throw ApiException::validation(['vencimento' => 'Data inválida.']);
                }
                $v = $v ?: null;
            } elseif ($k === 'emailCobranca' && $v !== null && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                throw ApiException::validation(['emailCobranca' => 'E-mail inválido.']);
            }
            $sets[] = "{$col} = ?";
            $vals[] = $v;
        }
        if (!$sets) {
            return;
        }
        $n = $this->db->run('UPDATE INSTITUICAO SET ' . implode(', ', $sets) . ', DATA_EDICAO = NOW() WHERE ID = ?', [...$vals, $instituicaoId])->rowCount();
        if ($n === 0 && !$this->db->run('SELECT 1 FROM INSTITUICAO WHERE ID = ?', [$instituicaoId])->fetchColumn()) {
            throw ApiException::notFound();
        }
        $this->audit->log($op, 'contrato_instituicao', 'INSTITUICAO', $instituicaoId, null, $in);
    }

    /** Sugestão para a fatura: alunos ativos/inativos e boletos ainda não cobrados. */
    public function sugestao(AuthUser $op, int $instituicaoId): array
    {
        $this->exigirOperador($op);
        $i = $this->db->run('SELECT ALUNOS_QTD_MAX, DATA_VENCIMENTO FROM INSTITUICAO WHERE ID = ?', [$instituicaoId])->fetch();
        if (!$i) {
            throw ApiException::notFound();
        }
        $cont = $this->db->run(
            'SELECT SUM(INATIVO = 0) AS ATIVOS, SUM(INATIVO = 1) AS INATIVOS FROM USUARIO WHERE INSTITUICAO_ID = ? AND LISTA_PERFIL_ID = 3',
            [$instituicaoId]
        )->fetch();
        $boletos = $this->db->run(
            'SELECT COUNT(*) AS QTD, COALESCE(SUM(Valor), 0) AS TOTAL FROM AdalineBoletoCloudExtrato WHERE FkInstituicaoId = ? AND Cobrado = 0',
            [$instituicaoId]
        )->fetch();
        $ativos = (int) $cont['ATIVOS'];
        return [
            'ativos' => $ativos,
            'inativos' => (int) $cont['INATIVOS'],
            'excedentes' => $i['ALUNOS_QTD_MAX'] ? max(0, $ativos - (int) $i['ALUNOS_QTD_MAX']) : 0,
            'boletosQtd' => (int) $boletos['QTD'],
            'valorBoletos' => round((float) $boletos['TOTAL'], 2),
            'vencimento' => $i['DATA_VENCIMENTO'],
        ];
    }

    /** Lista de faturas (operador: todas ou de uma instituição; admin da escola: só as próprias). */
    public function faturas(AuthUser $u, ?int $instituicaoId = null): array
    {
        $operador = $this->ehOperador($u);
        if (!$operador && !$u->isAdmin()) {
            throw ApiException::forbidden();
        }
        $inst = $operador ? $instituicaoId : $u->instituicaoId;
        $rows = $this->db->run(
            'SELECT f.*, i.FANTASIA FROM AdalineCobranca f JOIN INSTITUICAO i ON i.ID = f.FkInstituicaoId'
            . ($inst ? ' WHERE f.FkInstituicaoId = ?' : '') . ' ORDER BY f.DataVencimento DESC, f.Id DESC LIMIT 300',
            $inst ? [$inst] : []
        )->fetchAll();
        return array_map(static fn ($f) => [
            'id' => (int) $f['Id'], 'instituicaoId' => (int) $f['FkInstituicaoId'], 'instituicao' => $f['FANTASIA'],
            'situacao' => $f['FkSituacao'], 'vencimento' => $f['DataVencimento'], 'fechamento' => $f['FechamentoFatura'],
            'ativos' => $f['Ativos'] !== null ? (int) $f['Ativos'] : null, 'inativos' => $f['Inativos'] !== null ? (int) $f['Inativos'] : null,
            'valorPlano' => (float) $f['ValorPlano'], 'valorExcedente' => (float) $f['ValorExcedente'], 'valorBoletos' => (float) $f['ValorBoletos'],
            'valorTotal' => (float) $f['ValorTotal'], 'pagamento' => $f['DataPagamento'], 'valorPago' => $f['ValorPago'] !== null ? (float) $f['ValorPago'] : null,
            'url2Via' => $f['FkSituacao'] === self::ABERTO ? $f['BoletoCloudUrl2Via'] : null,
            'boletoEmitido' => (string) $f['BoletoCloudToken2Via'] !== '',
        ], $rows);
    }

    /** Cria a fatura do mês. Total = plano + excedente + boletos (os boletos do extrato ficam vinculados). */
    public function criarFatura(AuthUser $op, array $in): int
    {
        $this->exigirOperador($op);
        $instId = (int) ($in['instituicaoId'] ?? 0);
        $venc = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($in['vencimento'] ?? ''));
        $num = static function ($v, string $campo) {
            if ($v === null || $v === '') {
                return 0.0;
            }
            $v = str_replace(',', '.', (string) $v);
            if (!is_numeric($v) || (float) $v < 0) {
                throw ApiException::validation([$campo => 'Valor inválido.']);
            }
            return round((float) $v, 2);
        };
        $plano = $num($in['valorPlano'] ?? null, 'valorPlano');
        $exc = $num($in['valorExcedente'] ?? null, 'valorExcedente');
        if (!$venc || $venc->format('Y-m-d') !== ($in['vencimento'] ?? '')) {
            throw ApiException::validation(['vencimento' => 'Data inválida.']);
        }
        $sug = $this->sugestao($op, $instId);
        $total = round($plano + $exc + $sug['valorBoletos'], 2);
        if ($total <= 0) {
            throw ApiException::validation(['valorPlano' => 'A fatura precisa ter valor.']);
        }
        $this->db->beginTransaction();
        try {
            $this->db->run(
                'INSERT INTO AdalineCobranca (FkInstituicaoId, FkSituacao, DataVencimento, FechamentoFatura, Ativos, Inativos, ValorPlano,
                    ValorExcedente, ValorBoletos, ValorTotal, DataCadastro) VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, NOW())',
                [$instId, self::ABERTO, $venc->format('Y-m-d'), $sug['ativos'], $sug['inativos'], $plano, $exc, $sug['valorBoletos'], $total]
            );
            $id = $this->db->lastInsertId();
            $this->db->run('UPDATE AdalineBoletoCloudExtrato SET Cobrado = 1, FkCobrancaId = ?, DataEdicao = NOW() WHERE FkInstituicaoId = ? AND Cobrado = 0',
                [$id, $instId]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->audit->log($op, 'criar_fatura_adaline', 'AdalineCobranca', $id, null, ['instituicao' => $instId, 'total' => $total]);
        return $id;
    }

    /** Cancela fatura em aberto e devolve os boletos do extrato para a próxima cobrança. */
    public function cancelarFatura(AuthUser $op, int $id): void
    {
        $this->exigirOperador($op);
        $f = $this->fatura($id);
        if ($f['FkSituacao'] !== self::ABERTO) {
            throw new ApiException('Só faturas em aberto podem ser canceladas.', 409, 'conflict');
        }
        $this->db->run('UPDATE AdalineCobranca SET FkSituacao = ?, DataEdicao = NOW() WHERE Id = ?', [self::CANCELADO, $id]);
        $this->db->run('UPDATE AdalineBoletoCloudExtrato SET Cobrado = 0, FkCobrancaId = NULL WHERE FkCobrancaId = ?', [$id]);
        $this->audit->log($op, 'cancelar_fatura_adaline', 'AdalineCobranca', $id);
    }

    /** Emite o boleto da fatura na conta BoletoCloud da Adaline (idempotente). */
    public function emitirBoleto(AuthUser $op, int $id): string
    {
        $this->exigirOperador($op);
        $f = $this->fatura($id);
        if ($f['FkSituacao'] !== self::ABERTO) {
            throw new ApiException('Fatura não está em aberto.', 409, 'conflict');
        }
        if ($f['BoletoCloudUrl2Via']) {
            return $f['BoletoCloudUrl2Via'];
        }
        if ($this->contaToken === '') {
            throw new ApiException('Conta BoletoCloud da Adaline não configurada (ADALINE_BOLETOCLOUD_CONTA_TOKEN).', 422, 'sem_conta');
        }
        $i = $this->db->run('SELECT * FROM INSTITUICAO WHERE ID = ?', [(int) $f['FkInstituicaoId']])->fetch();
        if (!$i['EMAIL_COBRANCA'] || !$i['CNPJ']) {
            throw ApiException::validation(['instituicao' => 'Cadastre CNPJ e e-mail de cobrança da instituição.']);
        }
        $doc = $this->proximoDocumento();
        $t = new Titulo($doc, (int) $i['ID'], (float) $f['ValorTotal'], substr($f['DataVencimento'], 0, 10), 0,
            'Studying Online - fatura', [
                'nome' => (string) ($i['RAZAO_SOCIAL'] ?: $i['FANTASIA']), 'email' => (string) $i['EMAIL_COBRANCA'],
                'cpf' => (string) $i['CNPJ'], 'cep' => (string) $i['CEP'], 'uf' => (string) $i['UF'], 'cidade' => (string) $i['CIDADE'],
                'bairro' => (string) $i['BAIRRO'], 'rua' => (string) $i['RUA'], 'numero' => (string) $i['NUMERO'], 'dataNascimento' => null,
            ]);
        $conta = new ContaGateway(0, 'BoletoCloud', $this->contaToken,
            sprintf('Ativos: %d | Inativos: %d | Excedente: R$ %s | Boletos: R$ %s', (int) $f['Ativos'], (int) $f['Inativos'],
                number_format((float) $f['ValorExcedente'], 2, ',', '.'), number_format((float) $f['ValorBoletos'], 2, ',', '.')));
        try {
            $e = $this->boletoCloud->emitir($t, $conta);
        } catch (GatewayException $ex) {
            $this->logger->error('Falha ao emitir boleto da fatura Adaline', ['fatura' => $id, 'erro' => $ex->getMessage()]);
            throw new ApiException('BoletoCloud não respondeu. Tente novamente.', 502, 'gateway');
        }
        $this->db->run('UPDATE AdalineCobranca SET BoletoCloudToken2Via = ?, BoletoCloudUrl2Via = ?, DataEdicao = NOW() WHERE Id = ?',
            [$e->tokenBoleto, $e->url, $id]);
        $this->audit->log($op, 'emitir_boleto_adaline', 'AdalineCobranca', $id);
        return $e->url;
    }

    /**
     * Baixa das faturas pagas (antes UpdateCreditSistema). Fatura paga estende o vencimento
     * do contrato da instituição em um mês, como no legado. Chamada pela rotina diária.
     */
    public function sincronizar(): int
    {
        if ($this->contaToken === '') {
            return 0;
        }
        try {
            $liq = $this->boletoCloud->liquidacoes(new ContaGateway(0, 'BoletoCloud', $this->contaToken), 5);
        } catch (GatewayException $e) {
            $this->logger->warning('Sincronização das faturas Adaline falhou: ' . $e->getMessage());
            return 0;
        }
        $n = 0;
        foreach ($liq as $token => $data) {
            $f = $this->db->run("SELECT * FROM AdalineCobranca WHERE BoletoCloudToken2Via = ? AND FkSituacao = 'Em Aberto'", [$token])->fetch();
            if (!$f) {
                continue;
            }
            $this->db->beginTransaction();
            try {
                $this->db->run('UPDATE AdalineCobranca SET FkSituacao = ?, DataPagamento = ?, ValorPago = ValorTotal, DataEdicao = NOW() WHERE Id = ?',
                    [self::PAGO, $data, (int) $f['Id']]);
                $this->db->run(
                    'UPDATE INSTITUICAO SET DATA_VENCIMENTO = DATE_ADD(COALESCE(DATA_VENCIMENTO, CURDATE()), INTERVAL 1 MONTH), ATIVO = 1 WHERE ID = ?',
                    [(int) $f['FkInstituicaoId']]
                );
                $this->db->commit();
                $n++;
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
        }
        return $n;
    }

    /** Registra no extrato um boleto emitido pela escola no BoletoCloud (cobrado depois na fatura). */
    public function registrarBoletoEmitido(int $instituicaoId, int $contaReceberId, string $token, string $vencimento): void
    {
        if ($this->tarifaBoleto <= 0) {
            return;
        }
        $cobra = (bool) $this->db->run('SELECT COBRAR_BOLETOS FROM INSTITUICAO WHERE ID = ?', [$instituicaoId])->fetchColumn();
        if (!$cobra) {
            return;
        }
        $this->db->run(
            'INSERT INTO AdalineBoletoCloudExtrato (FkInstituicaoId, FkContasReceberId, TokenBoletoClound2Via, Valor, DataVencimento, DataCriacao, DataEdicao, Cobrado)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 0)',
            [$instituicaoId, $contaReceberId, $token, $this->tarifaBoleto, $vencimento]
        );
    }

    /** Extrato de boletos emitidos (admin da escola: os próprios). */
    public function extrato(AuthUser $u): array
    {
        if (!$u->isAdmin()) {
            throw ApiException::forbidden();
        }
        $rows = $this->db->run(
            'SELECT e.Id, e.FkContasReceberId, e.Valor, e.DataVencimento, e.DataCriacao, e.Cobrado, e.FkCobrancaId, u.NOME
               FROM AdalineBoletoCloudExtrato e
               LEFT JOIN CONTAS_RECEBER cr ON cr.ID = e.FkContasReceberId
               LEFT JOIN USUARIO u ON u.ID = cr.USUARIO_ID
              WHERE e.FkInstituicaoId = ? ORDER BY e.DataCriacao DESC LIMIT 500',
            [$u->instituicaoId]
        )->fetchAll();
        return array_map(static fn ($r) => [
            'id' => (int) $r['Id'], 'aluno' => $r['NOME'], 'valor' => (float) $r['Valor'], 'vencimento' => $r['DataVencimento'],
            'emitidoEm' => $r['DataCriacao'], 'cobrado' => (bool) $r['Cobrado'], 'faturaId' => $r['FkCobrancaId'] !== null ? (int) $r['FkCobrancaId'] : null,
        ], $rows);
    }

    private function fatura(int $id): array
    {
        $f = $this->db->run('SELECT * FROM AdalineCobranca WHERE Id = ?', [$id])->fetch();
        if (!$f) {
            throw ApiException::notFound('Fatura não encontrada.');
        }
        return $f;
    }

    /** Número de documento sequencial (o legado dobrava o contador a cada emissão). */
    private function proximoDocumento(): int
    {
        $this->db->run('UPDATE AdalineConfiguracao SET BoletoNumDoc = BoletoNumDoc + 1, DataEdicao = NOW() ORDER BY Id LIMIT 1');
        return (int) $this->db->run('SELECT BoletoNumDoc FROM AdalineConfiguracao ORDER BY Id LIMIT 1')->fetchColumn();
    }
}
