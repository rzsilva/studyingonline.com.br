<?php

declare(strict_types=1);

namespace App\Domain\Financeiro;

use App\Support\Segredo;
use App\Domain\Adaline\AdalineService;
use App\Domain\Auth\AuthUser;
use App\Integrations\Pagamento\BoletoCloudGateway;
use App\Integrations\Pagamento\ContaGateway;
use App\Integrations\Pagamento\Gateway;
use App\Integrations\Pagamento\GatewayException;
use App\Integrations\Pagamento\MercadoPagoGateway;
use App\Integrations\Pagamento\PagSeguroGateway;
use App\Integrations\Pagamento\Situacao;
use App\Integrations\Pagamento\Titulo;
use App\Integrations\Pagamento\VindiGateway;
use App\Support\ApiException;
use App\Support\Audit;
use App\Support\Connection;
use Psr\Log\LoggerInterface;

/**
 * Cobrança dos títulos (CONTAS_RECEBER) nos gateways e baixa automática.
 * Regras portadas do legado (InscricaoController.GerarFatura, ArquivoRetornoController,
 * ContasReceberController.GerarCobrancaAlunos), com correções:
 *  - emissão idempotente (não gera dois boletos para o mesmo título);
 *  - baixa só por consulta ao provedor (webhook nunca é confiado sozinho) e por instituição;
 *  - eventos registrados em PAGAMENTO_EVENTO (idempotência + auditoria);
 *  - pagamento de matrícula/rematrícula reativa o aluno (como no legado).
 */
final class CobrancaService
{
    private const RECEBIDO = 2;
    private const CANCELADO = 4;
    private const FORMAS = ['boleto' => ['BoletoCloud', 'MercadoPago'], 'cartao' => ['Vindi', 'PagSeguro', 'MercadoPago']];

    /** @var array<string,Gateway> */
    private array $gateways;

    public function __construct(
        private readonly Connection $db,
        private readonly Audit $audit,
        private readonly LoggerInterface $logger,
        private readonly string $webhookSecret,
        BoletoCloudGateway $boletoCloud,
        VindiGateway $vindi,
        PagSeguroGateway $pagSeguro,
        private readonly MercadoPagoGateway $mercadoPago,
        private readonly AdalineService $adaline,
        private readonly Segredo $segredo,
    ) {
        foreach ([$boletoCloud, $vindi, $pagSeguro, $mercadoPago] as $g) {
            $this->gateways[$g->nome()] = $g;
        }
    }

    /* ============================ EMISSÃO ============================ */

    /**
     * Emite (ou reaproveita) a cobrança de um título e devolve a URL de pagamento.
     * Aluno só cobra os próprios títulos; admin qualquer título da instituição.
     */
    public function pagar(AuthUser $user, int $tituloId, string $forma = 'boleto'): array
    {
        if (!isset(self::FORMAS[$forma])) {
            throw ApiException::validation(['forma' => 'Use "boleto" ou "cartao".']);
        }
        $row = $this->titulo($user->instituicaoId, $tituloId);
        if (!$user->canAccessUser((int) $row['USUARIO_ID']) || (!$user->isAdmin() && !$user->isAluno())) {
            throw ApiException::notFound();
        }
        $situacao = (int) $row['LISTA_SITUACAO_CR_ID'];
        if ($situacao === self::RECEBIDO) {
            throw new ApiException('Este título já está pago.', 409, 'pago');
        }
        if ($situacao === self::CANCELADO) {
            throw new ApiException('Este título foi cancelado.', 409, 'cancelado');
        }

        $t = Titulo::fromRow($row);
        [$gateway, $conta] = $this->gatewayDaForma($user->instituicaoId, $forma);

        // idempotência: título já emitido neste gateway → só devolve a 2ª via
        $jaEmitido = $gateway instanceof BoletoCloudGateway ? $t->tokenBoleto : $t->tokenCartao;
        if ($jaEmitido && ($url = $gateway->urlSegundaVia($t))) {
            return ['url' => $url, 'gateway' => $gateway->nome(), 'segundaVia' => true];
        }

        try {
            $e = $gateway instanceof MercadoPagoGateway
                ? $gateway->emitir($t, $conta, $this->webhookToken($user->instituicaoId))
                : $gateway->emitir($t, $conta);
        } catch (GatewayException $ex) {
            $this->logger->error('Falha ao emitir cobrança', ['titulo' => $tituloId, 'gateway' => $gateway->nome(), 'erro' => $ex->getMessage()]);
            throw new ApiException('O provedor de pagamento não respondeu. Tente novamente em instantes.', 502, 'gateway');
        }
        $this->db->run(
            'UPDATE CONTAS_RECEBER SET TOKEN_BOLETOCLOUD = COALESCE(?, TOKEN_BOLETOCLOUD), TOKEN_CC = COALESCE(?, TOKEN_CC),
                    NUM_DOCUMENTO = COALESCE(?, NUM_DOCUMENTO), DATA_EDICAO = NOW() WHERE ID = ? AND INSTITUICAO_ID = ?',
            [$e->tokenBoleto, $e->tokenCartao, $e->numDocumento, $tituloId, $user->instituicaoId]
        );
        if ($e->tokenBoleto && $gateway instanceof BoletoCloudGateway) {
            // tarifa do boleto entra no extrato da Adaline (cobrada na fatura da instituição)
            $this->adaline->registrarBoletoEmitido($user->instituicaoId, $tituloId, $e->tokenBoleto, $t->vencimento);
        }
        $this->audit->log($user, 'emitir_cobranca', 'CONTAS_RECEBER', $tituloId, null, ['gateway' => $gateway->nome()]);
        return ['url' => $e->url, 'gateway' => $gateway->nome(), 'segundaVia' => false];
    }

    /** Gateways configurados para a instituição (o que o aluno pode escolher). */
    public function formasDisponiveis(int $instituicaoId): array
    {
        $bancos = $this->db->run(
            "SELECT DISTINCT BANCO FROM CONTA_BANCARIA WHERE INSTITUICAO_ID = ? AND AUTORIZAR = 1
               AND GATEWAY_TOKEN_PROD IS NOT NULL AND GATEWAY_TOKEN_PROD <> ''",
            [$instituicaoId]
        )->fetchAll(\PDO::FETCH_COLUMN);
        $out = [];
        foreach (self::FORMAS as $forma => $aceitos) {
            if (array_intersect($aceitos, $bancos)) {
                $out[] = $forma;
            }
        }
        return $out;
    }

    /* ======================= BAIXA / SINCRONIZAÇÃO ======================= */

    /**
     * Consulta nos provedores os títulos em aberto já emitidos e dá baixa nos pagos
     * (substitui as rotinas *AtualizarStatusContaReceber do legado). Retorna contadores.
     */
    public function sincronizar(int $instituicaoId, ?AuthUser $por = null): array
    {
        $res = ['consultados' => 0, 'pagos' => 0, 'cancelados' => 0, 'erros' => 0];
        $contas = $this->contasAtivas($instituicaoId);
        $titulos = $this->db->run(
            "SELECT cr.*, u.NOME, u.EMAIL, u.CPF, u.CEP, u.UF, u.CIDADE, u.BAIRRO, u.RUA, u.NUMERO, u.DATA_NASCIMENTO
               FROM CONTAS_RECEBER cr JOIN USUARIO u ON u.ID = cr.USUARIO_ID
              WHERE cr.INSTITUICAO_ID = ? AND cr.LISTA_SITUACAO_CR_ID NOT IN (2, 4)
                AND ((cr.TOKEN_BOLETOCLOUD IS NOT NULL AND cr.TOKEN_BOLETOCLOUD <> '') OR (cr.TOKEN_CC IS NOT NULL AND cr.TOKEN_CC <> ''))",
            [$instituicaoId]
        )->fetchAll();

        // BoletoCloud: uma consulta por conta (liquidações dos últimos dias), não por título
        $liquidados = [];
        if (isset($contas['BoletoCloud'])) {
            try {
                $liquidados = $this->gateways['BoletoCloud']->liquidacoes($contas['BoletoCloud'], 5);
            } catch (GatewayException $e) {
                $res['erros']++;
                $this->logger->warning('BoletoCloud indisponível na sincronização: ' . $e->getMessage());
            }
        }

        foreach ($titulos as $row) {
            $t = Titulo::fromRow($row);
            $res['consultados']++;
            try {
                if ($t->tokenBoleto && isset($contas['BoletoCloud'])) {
                    $s = isset($liquidados[$t->tokenBoleto])
                        ? new Situacao(Situacao::PAGO, $liquidados[$t->tokenBoleto], "boletocloud:{$t->tokenBoleto}")
                        : new Situacao(Situacao::PENDENTE);
                } else {
                    $g = $this->gatewayDoTitulo($t, $contas);
                    if (!$g) {
                        continue;
                    }
                    $s = $g[0]->consultar($t, $g[1]);
                }
            } catch (GatewayException $e) {
                $res['erros']++;
                $this->logger->warning("Falha ao consultar título {$t->id}: " . $e->getMessage());
                continue;
            }
            if ($s->status === Situacao::PAGO && $this->baixar($instituicaoId, $t->id, $s, 'sincronizacao')) {
                $res['pagos']++;
            } elseif ($s->status === Situacao::CANCELADO && $this->cancelarPorGateway($instituicaoId, $t->id, $s)) {
                $res['cancelados']++;
            }
        }
        if ($por) {
            $this->audit->log($por, 'sincronizar_pagamentos', 'CONTAS_RECEBER', null, null, $res);
        }
        return $res;
    }

    /**
     * Webhook do MercadoPago. A URL leva a instituição e um token HMAC; o corpo só informa o id
     * do pagamento, que é CONSULTADO na API com a credencial da própria instituição.
     */
    public function webhookMercadoPago(int $instituicaoId, string $token, array $corpo): string
    {
        if (!hash_equals($this->webhookToken($instituicaoId), $token)) {
            throw ApiException::forbidden('Notificação não autorizada.');
        }
        $tipo = (string) ($corpo['type'] ?? $corpo['topic'] ?? '');
        $paymentId = (string) ($corpo['data']['id'] ?? $corpo['id'] ?? '');
        if ($tipo !== 'payment' || $paymentId === '') {
            return 'ignorado';
        }
        $conta = $this->contasAtivas($instituicaoId)['MercadoPago'] ?? null;
        if (!$conta) {
            throw ApiException::notFound('MercadoPago não configurado.');
        }
        try {
            $p = $this->mercadoPago->pagamento($conta, $paymentId);
        } catch (GatewayException $e) {
            $this->logger->warning('Webhook MercadoPago: consulta falhou: ' . $e->getMessage());
            throw new ApiException('Falha ao consultar o pagamento.', 502, 'gateway'); // o MP reenvia depois
        }
        if (!preg_match('/^so-cr-(\d+)$/', (string) ($p['external_reference'] ?? ''), $m)) {
            return 'ignorado';
        }
        $s = $this->mercadoPago->situacaoPagamento($p);
        if ($s->status === Situacao::PAGO) {
            return $this->baixar($instituicaoId, (int) $m[1], $s, 'webhook') ? 'baixado' : 'ja_processado';
        }
        if ($s->status === Situacao::CANCELADO) {
            return $this->cancelarPorGateway($instituicaoId, (int) $m[1], $s) ? 'cancelado' : 'ja_processado';
        }
        return 'pendente';
    }

    /** URL a cadastrar no painel do MercadoPago (também enviada em cada preferência). */
    public function webhookUrl(int $instituicaoId): string
    {
        return $this->mercadoPago->webhookUrl($instituicaoId, $this->webhookToken($instituicaoId));
    }

    public function webhookToken(int $instituicaoId): string
    {
        return substr(hash_hmac('sha256', "mp-webhook:{$instituicaoId}", $this->webhookSecret), 0, 40);
    }

    /** Baixa manual pela secretaria (pagamento em dinheiro, transferência...). */
    public function baixaManual(AuthUser $admin, int $tituloId, ?string $data, ?string $obs): void
    {
        $row = $this->titulo($admin->instituicaoId, $tituloId);
        if ((int) $row['LISTA_SITUACAO_CR_ID'] === self::RECEBIDO) {
            throw new ApiException('Este título já está pago.', 409, 'pago');
        }
        $d = $data ? \DateTimeImmutable::createFromFormat('!Y-m-d', $data) : new \DateTimeImmutable('today');
        if (!$d || $d > new \DateTimeImmutable('today')) {
            throw ApiException::validation(['dataPagamento' => 'Data de pagamento inválida.']);
        }
        $this->baixar($admin->instituicaoId, $tituloId, new Situacao(Situacao::PAGO, $d->format('Y-m-d'), "manual:{$tituloId}:" . time()), 'manual', $obs);
        $this->audit->log($admin, 'baixa_manual', 'CONTAS_RECEBER', $tituloId, null, ['data' => $d->format('Y-m-d')]);
    }

    public function cancelar(AuthUser $admin, int $tituloId, string $motivo): void
    {
        $row = $this->titulo($admin->instituicaoId, $tituloId);
        if ((int) $row['LISTA_SITUACAO_CR_ID'] === self::RECEBIDO) {
            throw new ApiException('Título pago não pode ser cancelado aqui (faça o estorno no provedor).', 409, 'pago');
        }
        if (trim($motivo) === '') {
            throw ApiException::validation(['motivo' => 'Informe o motivo do cancelamento.']);
        }
        $this->db->run(
            "UPDATE CONTAS_RECEBER SET LISTA_SITUACAO_CR_ID = 4, DATA_EDICAO = NOW(),
                    OBSERVACAO = TRIM(CONCAT(COALESCE(OBSERVACAO, ''), ' [Cancelado: ', ?, ']')) WHERE ID = ? AND INSTITUICAO_ID = ?",
            [mb_substr(trim($motivo), 0, 200), $tituloId, $admin->instituicaoId]
        );
        $this->audit->log($admin, 'cancelar_titulo', 'CONTAS_RECEBER', $tituloId, null, ['motivo' => $motivo]);
    }

    /* ======================= GERAÇÃO EM LOTE ======================= */

    /**
     * Mensalidades do mês (antes GerarCobrancaAlunos, que rodava para TODAS as instituições sem login).
     * Para cada aluno ativo e curso ativo com valor: valor do curso − desconto do aluno, vencimento no
     * dia de vencimento do aluno; respeita PERIODICIDADE_COBRANCA e não duplica.
     */
    public function gerarMensalidades(AuthUser $admin, string $competencia, bool $simular = false): array
    {
        $mes = self::competencia($competencia);
        if (!$mes) {
            throw ApiException::validation(['competencia' => 'Use AAAA-MM.']);
        }
        $alunos = $this->db->run(
            'SELECT u.ID AS USUARIO_ID, u.NOME, u.DESCONTO, u.DIA_VENCIMENTO, c.ID AS CURSO_ID, c.NOME AS CURSO, c.VALOR,
                    COALESCE(NULLIF(c.PERIODICIDADE_COBRANCA, 0), 1) AS PERIODICIDADE
               FROM USUARIO u JOIN USUARIO_CURSO uc ON uc.USUARIO_ID = u.ID JOIN CURSO c ON c.ID = uc.CURSO_ID
              WHERE u.INSTITUICAO_ID = ? AND u.LISTA_PERFIL_ID = 3 AND u.INATIVO = 0 AND c.ATIVO = 1 AND c.VALOR > 0
              ORDER BY u.NOME',
            [$admin->instituicaoId]
        )->fetchAll();

        $gerados = [];
        $ignorados = 0;
        $jaGerado = []; // como no legado: no máximo UMA mensalidade por aluno por competência
        $this->db->beginTransaction();
        try {
            foreach ($alunos as $a) {
                $valor = round((float) $a['VALOR'] - (float) $a['DESCONTO'], 2);
                if ($valor <= 0 || isset($jaGerado[$a['USUARIO_ID']])) {
                    $ignorados++;
                    continue;
                }
                $dia = min(max((int) $a['DIA_VENCIMENTO'] ?: 15, 1), (int) $mes->format('t'));
                $venc = $mes->setDate((int) $mes->format('Y'), (int) $mes->format('m'), $dia)->format('Y-m-d');
                // já existe mensalidade (não cancelada) deste aluno nesta competência, ou dentro da periodicidade?
                $ultima = $this->db->run(
                    'SELECT MAX(DATA_VENCIMENTO) FROM CONTAS_RECEBER
                      WHERE USUARIO_ID = ? AND INSTITUICAO_ID = ? AND LISTA_CATEGORIA_CR_ID = 1 AND LISTA_SITUACAO_CR_ID <> 4',
                    [$a['USUARIO_ID'], $admin->instituicaoId]
                )->fetchColumn();
                if ($ultima) {
                    $proxima = (new \DateTimeImmutable(substr($ultima, 0, 7) . '-01'))->modify('+' . (int) $a['PERIODICIDADE'] . ' months');
                    if ($proxima > $mes) {
                        $ignorados++;
                        continue;
                    }
                }
                if (!$simular) {
                    $this->db->run(
                        'INSERT INTO CONTAS_RECEBER (INSTITUICAO_ID, USUARIO_ID, DATA_VENCIMENTO, LISTA_SITUACAO_CR_ID, LISTA_CATEGORIA_CR_ID,
                            VALOR, OBSERVACAO, DATA_CADASTRO) VALUES (?, ?, ?, 1, 1, ?, ?, NOW())',
                        [$admin->instituicaoId, $a['USUARIO_ID'], $venc, $valor, "Mensalidade {$mes->format('m/Y')} - {$a['CURSO']}"]
                    );
                }
                $jaGerado[$a['USUARIO_ID']] = true;
                $gerados[] = ['aluno' => $a['NOME'], 'curso' => $a['CURSO'], 'vencimento' => $venc, 'valor' => $valor];
            }
            $simular ? $this->db->rollBack() : $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        if (!$simular) {
            $this->audit->log($admin, 'gerar_mensalidades', 'CONTAS_RECEBER', null, null, ['competencia' => $competencia, 'qtd' => count($gerados)]);
        }
        return ['gerados' => $gerados, 'ignorados' => $ignorados, 'total' => round(array_sum(array_column($gerados, 'valor')), 2)];
    }

    /** Contas a pagar do mês a partir das contas fixas (sem duplicar). */
    public function gerarContasFixas(AuthUser $admin, string $competencia): int
    {
        $mes = self::competencia($competencia);
        if (!$mes) {
            throw ApiException::validation(['competencia' => 'Use AAAA-MM.']);
        }
        $n = 0;
        foreach ($this->db->run('SELECT * FROM CONTAS_FIXAS WHERE INSTITUICAO_ID = ?', [$admin->instituicaoId])->fetchAll() as $f) {
            $dia = min(max((int) $f['DIA_VENCIMENTO'], 1), (int) $mes->format('t'));
            $venc = $mes->format('Y-m-') . sprintf('%02d', $dia);
            $existe = $this->db->run('SELECT 1 FROM CONTAS_PAGAR WHERE INSTITUICAO_ID = ? AND DESCRICAO = ? AND DATA_VENCIMENTO = ?',
                [$admin->instituicaoId, $f['DESCRICAO'], $venc])->fetchColumn();
            if (!$existe) {
                $this->db->run(
                    'INSERT INTO CONTAS_PAGAR (INSTITUICAO_ID, LISTA_SITUACAO_CP_ID, LISTA_CATEGORIA_CP_ID, DATA_VENCIMENTO, DESCRICAO, VALOR, DATA_CADASTRO)
                     VALUES (?, 1, ?, ?, ?, ?, NOW())',
                    [$admin->instituicaoId, $f['LISTA_CATEGORIA_CP_ID'], $venc, $f['DESCRICAO'], $f['VALOR']]
                );
                $n++;
            }
        }
        $this->audit->log($admin, 'gerar_contas_fixas', 'CONTAS_PAGAR', null, null, ['competencia' => $competencia, 'qtd' => $n]);
        return $n;
    }

    /* ============================ RELATÓRIO ============================ */

    /** Resumo do período: recebido, a receber, vencido, pago e a pagar. */
    public function resumo(int $instituicaoId, string $de, string $ate): array
    {
        $ok = static fn ($d) => (bool) \DateTimeImmutable::createFromFormat('!Y-m-d', $d);
        if (!$ok($de) || !$ok($ate) || $de > $ate) {
            throw ApiException::validation(['periodo' => 'Período inválido.']);
        }
        $cr = $this->db->run(
            "SELECT
                COALESCE(SUM(CASE WHEN LISTA_SITUACAO_CR_ID = 2 THEN VALOR END), 0) AS RECEBIDO,
                COALESCE(SUM(CASE WHEN LISTA_SITUACAO_CR_ID NOT IN (2, 4) AND DATA_VENCIMENTO >= CURDATE() THEN VALOR END), 0) AS A_RECEBER,
                COALESCE(SUM(CASE WHEN LISTA_SITUACAO_CR_ID NOT IN (2, 4) AND DATA_VENCIMENTO < CURDATE() THEN VALOR END), 0) AS VENCIDO,
                COUNT(CASE WHEN LISTA_SITUACAO_CR_ID NOT IN (2, 4) AND DATA_VENCIMENTO < CURDATE() THEN 1 END) AS QTD_VENCIDOS
               FROM CONTAS_RECEBER WHERE INSTITUICAO_ID = ? AND DATA_VENCIMENTO BETWEEN ? AND ?",
            [$instituicaoId, $de, $ate]
        )->fetch();
        $cp = $this->db->run(
            "SELECT COALESCE(SUM(CASE WHEN DATA_PAGAMENTO IS NOT NULL THEN VALOR END), 0) AS PAGO,
                    COALESCE(SUM(CASE WHEN DATA_PAGAMENTO IS NULL THEN VALOR END), 0) AS A_PAGAR
               FROM CONTAS_PAGAR WHERE INSTITUICAO_ID = ? AND DATA_VENCIMENTO BETWEEN ? AND ?",
            [$instituicaoId, $de, $ate]
        )->fetch();
        $mensal = $this->db->run(
            "SELECT DATE_FORMAT(DATA_VENCIMENTO, '%Y-%m') AS MES,
                    COALESCE(SUM(CASE WHEN LISTA_SITUACAO_CR_ID = 2 THEN VALOR END), 0) AS RECEBIDO,
                    COALESCE(SUM(CASE WHEN LISTA_SITUACAO_CR_ID NOT IN (2, 4) THEN VALOR END), 0) AS ABERTO
               FROM CONTAS_RECEBER WHERE INSTITUICAO_ID = ? AND DATA_VENCIMENTO BETWEEN ? AND ?
              GROUP BY MES ORDER BY MES",
            [$instituicaoId, $de, $ate]
        )->fetchAll();
        return [
            'receber' => ['recebido' => (float) $cr['RECEBIDO'], 'aReceber' => (float) $cr['A_RECEBER'],
                'vencido' => (float) $cr['VENCIDO'], 'qtdVencidos' => (int) $cr['QTD_VENCIDOS']],
            'pagar' => ['pago' => (float) $cp['PAGO'], 'aPagar' => (float) $cp['A_PAGAR']],
            'mensal' => array_map(static fn ($m) => ['mes' => $m['MES'], 'recebido' => (float) $m['RECEBIDO'], 'aberto' => (float) $m['ABERTO']], $mensal),
        ];
    }

    /** Linhas para exportação CSV (abre no Excel). */
    public function exportarReceber(int $instituicaoId, string $de, string $ate): array
    {
        return $this->db->run(
            "SELECT cr.ID, u.NOME, u.MATRICULA, c.VALOR AS CATEGORIA, cr.DATA_VENCIMENTO, cr.DATA_PAGAMENTO, cr.VALOR, s.VALOR AS SITUACAO
               FROM CONTAS_RECEBER cr LEFT JOIN USUARIO u ON u.ID = cr.USUARIO_ID
               LEFT JOIN LISTA_CATEGORIA_CR c ON c.ID = cr.LISTA_CATEGORIA_CR_ID
               LEFT JOIN LISTA_SITUACAO_CR s ON s.ID = cr.LISTA_SITUACAO_CR_ID
              WHERE cr.INSTITUICAO_ID = ? AND cr.DATA_VENCIMENTO BETWEEN ? AND ?
              ORDER BY cr.DATA_VENCIMENTO, u.NOME",
            [$instituicaoId, $de, $ate]
        )->fetchAll();
    }

    /* ============================ INTERNOS ============================ */

    /** "AAAA-MM" estrito: o DateTime do PHP aceitaria "2026-13" como janeiro de 2027. */
    private static function competencia(string $c): ?\DateTimeImmutable
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m', $c);
        return $d && $d->format('Y-m') === $c ? $d : null;
    }

    /**
     * Baixa idempotente: registra o evento (único por gateway+evento+status) e marca o título.
     * Matrícula/rematrícula paga reativa o aluno.
     */
    private function baixar(int $instituicaoId, int $tituloId, Situacao $s, string $origem, ?string $obs = null): bool
    {
        $row = $this->db->run('SELECT ID, USUARIO_ID, LISTA_CATEGORIA_CR_ID, LISTA_SITUACAO_CR_ID FROM CONTAS_RECEBER WHERE ID = ? AND INSTITUICAO_ID = ?',
            [$tituloId, $instituicaoId])->fetch();
        if (!$row || (int) $row['LISTA_SITUACAO_CR_ID'] === self::RECEBIDO) {
            return false;
        }
        $this->db->beginTransaction();
        try {
            $novo = $this->db->run(
                'INSERT IGNORE INTO PAGAMENTO_EVENTO (INSTITUICAO_ID, GATEWAY, EVENTO_ID, CONTA_RECEBER_ID, STATUS, PAYLOAD, CRIADO_EM)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())',
                [$instituicaoId, explode(':', (string) $s->eventoId)[0] ?: $origem, (string) $s->eventoId, $tituloId, 'pago',
                    json_encode(['origem' => $origem, 'data' => $s->dataPagamento])]
            )->rowCount();
            if ($novo === 0) {
                $this->db->rollBack();
                return false; // evento já processado
            }
            $this->db->run(
                "UPDATE CONTAS_RECEBER SET LISTA_SITUACAO_CR_ID = 2, DATA_PAGAMENTO = ?, ARQUIVO_RETORNO = ?, DATA_EDICAO = NOW(),
                        OBSERVACAO = IF(? IS NULL, OBSERVACAO, TRIM(CONCAT(COALESCE(OBSERVACAO, ''), ' ', ?))) WHERE ID = ?",
                [$s->dataPagamento ?? date('Y-m-d'), date('Y-m-d'), $obs, $obs, $tituloId]
            );
            if (in_array((int) $row['LISTA_CATEGORIA_CR_ID'], [2, 3], true)) {
                $this->db->run('UPDATE USUARIO SET INATIVO = 0, DATA_UPDATE = NOW() WHERE ID = ? AND INSTITUICAO_ID = ?',
                    [(int) $row['USUARIO_ID'], $instituicaoId]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return true;
    }

    private function cancelarPorGateway(int $instituicaoId, int $tituloId, Situacao $s): bool
    {
        $n = $this->db->run(
            'INSERT IGNORE INTO PAGAMENTO_EVENTO (INSTITUICAO_ID, GATEWAY, EVENTO_ID, CONTA_RECEBER_ID, STATUS, CRIADO_EM) VALUES (?, ?, ?, ?, ?, NOW())',
            [$instituicaoId, explode(':', (string) $s->eventoId)[0], (string) $s->eventoId, $tituloId, 'cancelado']
        )->rowCount();
        if ($n === 0) {
            return false;
        }
        // cobrança cancelada no provedor: o título volta a "a receber" sem tokens, para poder ser cobrado de novo
        $this->db->run(
            "UPDATE CONTAS_RECEBER SET TOKEN_CC = NULL, DATA_EDICAO = NOW() WHERE ID = ? AND INSTITUICAO_ID = ? AND LISTA_SITUACAO_CR_ID NOT IN (2, 4)",
            [$tituloId, $instituicaoId]
        );
        return true;
    }

    private function titulo(int $instituicaoId, int $id): array
    {
        $row = $this->db->run(
            "SELECT cr.*, u.NOME, u.EMAIL, u.CPF, u.CEP, u.UF, u.CIDADE, u.BAIRRO, u.RUA, u.NUMERO, u.DATA_NASCIMENTO,
                    CONCAT(COALESCE(c.VALOR, 'Cobrança'), ' - ', COALESCE(i.FANTASIA, '')) AS DESCRICAO
               FROM CONTAS_RECEBER cr JOIN USUARIO u ON u.ID = cr.USUARIO_ID
               LEFT JOIN LISTA_CATEGORIA_CR c ON c.ID = cr.LISTA_CATEGORIA_CR_ID
               LEFT JOIN INSTITUICAO i ON i.ID = cr.INSTITUICAO_ID
              WHERE cr.ID = ? AND cr.INSTITUICAO_ID = ?",
            [$id, $instituicaoId]
        )->fetch();
        if (!$row) {
            throw ApiException::notFound('Título não encontrado.');
        }
        return $row;
    }

    /** @return array<string,ContaGateway> banco => conta ativa com token */
    private function contasAtivas(int $instituicaoId): array
    {
        $out = [];
        foreach ($this->db->run(
            "SELECT ID, BANCO, GATEWAY_TOKEN_PROD, INSTRUCOES, PAGSEGURO_EMAIL FROM CONTA_BANCARIA
              WHERE INSTITUICAO_ID = ? AND AUTORIZAR = 1 AND GATEWAY_TOKEN_PROD IS NOT NULL AND GATEWAY_TOKEN_PROD <> '' ORDER BY ID",
            [$instituicaoId]
        )->fetchAll() as $c) {
            $out[$c['BANCO']] ??= new ContaGateway((int) $c['ID'], $c['BANCO'], (string) $this->segredo->abrir($c['GATEWAY_TOKEN_PROD']), $c['INSTRUCOES'], $c['PAGSEGURO_EMAIL']);
        }
        return $out;
    }

    /** @return array{0: Gateway, 1: ContaGateway} */
    private function gatewayDaForma(int $instituicaoId, string $forma): array
    {
        $contas = $this->contasAtivas($instituicaoId);
        foreach (self::FORMAS[$forma] as $banco) {
            if (isset($contas[$banco], $this->gateways[$banco])) {
                return [$this->gateways[$banco], $contas[$banco]];
            }
        }
        throw new ApiException($forma === 'cartao' ? 'Pagamento com cartão não está disponível.' : 'Emissão de boleto não está disponível. Procure a secretaria.', 422, 'sem_gateway');
    }

    /** Gateway em que o título foi emitido (pelos tokens gravados). */
    private function gatewayDoTitulo(Titulo $t, array $contas): ?array
    {
        if ($t->numDocumento && str_starts_with($t->numDocumento, 'so-cr-') && isset($contas['MercadoPago'])) {
            return [$this->gateways['MercadoPago'], $contas['MercadoPago']];
        }
        if ($t->tokenCartao && $t->numDocumento && ctype_digit($t->numDocumento) && $t->numDocumento !== (string) $t->id && isset($contas['Vindi'])) {
            return [$this->gateways['Vindi'], $contas['Vindi']];
        }
        if ($t->tokenCartao && isset($contas['PagSeguro'])) {
            return [$this->gateways['PagSeguro'], $contas['PagSeguro']];
        }
        return null;
    }
}
