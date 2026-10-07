<?php

declare(strict_types=1);

namespace App\Domain\Financeiro;

use App\Domain\Auth\AuthUser;
use App\Domain\Auth\Perfil;
use App\Support\ApiException;
use App\Support\Crud\Field as F;
use App\Support\Crud\Resource;

/**
 * Cadastros financeiros (somente administrador).
 * LISTA_SITUACAO_CR: 1 a receber, 2 recebido, 3 atrasado, 4 cancelado.
 * LISTA_CATEGORIA_CR: 1 mensalidade, 2 matrícula, 3 rematrícula.
 */
final class Resources
{
    /** Financeiro é exclusivo do administrador (o padrão do CRUD libera leitura ao professor). */
    private static function soAdmin(Resource $r): Resource
    {
        $r->readRoles = [Perfil::Administrador];
        $r->writeRoles = [Perfil::Administrador];
        return $r;
    }

    public const GATEWAYS = ['BoletoCloud', 'MercadoPago', 'Vindi', 'PagSeguro'];

    public static function contasReceber(): Resource
    {
        $r = new Resource('CONTAS_RECEBER', 'Conta a receber');
        $r->fields(
            F::ref('USUARIO_ID', 'USUARIO')->required(),
            F::ref('LISTA_CATEGORIA_CR_ID', 'LISTA_CATEGORIA_CR', false)->default(1),
            F::ref('LISTA_SITUACAO_CR_ID', 'LISTA_SITUACAO_CR', false)->default(1),
            F::date('DATA_VENCIMENTO')->required(),
            F::date('DATA_PAGAMENTO'),
            F::decimal('VALOR')->required(),
            F::text('OBSERVACAO'),
        );
        $r->joins = [
            'LEFT JOIN USUARIO u ON u.ID = t.USUARIO_ID',
            'LEFT JOIN LISTA_SITUACAO_CR s ON s.ID = t.LISTA_SITUACAO_CR_ID',
            'LEFT JOIN LISTA_CATEGORIA_CR c ON c.ID = t.LISTA_CATEGORIA_CR_ID',
        ];
        // tokens de gateway não saem; só se há cobrança emitida
        $r->extraSelect = ['u.NOME AS ALUNO', 'u.MATRICULA', 's.VALOR AS SITUACAO', 'c.VALOR AS CATEGORIA', 't.NUM_DOCUMENTO',
            "(t.TOKEN_BOLETOCLOUD IS NOT NULL AND t.TOKEN_BOLETOCLOUD <> '') OR (t.TOKEN_CC IS NOT NULL AND t.TOKEN_CC <> '') AS COBRANCA_EMITIDA",
            "(t.LISTA_SITUACAO_CR_ID NOT IN (2, 4) AND t.DATA_VENCIMENTO < CURDATE()) AS VENCIDO"];
        $r->filters = ['situacaoId' => 't.LISTA_SITUACAO_CR_ID', 'categoriaId' => 't.LISTA_CATEGORIA_CR_ID', 'usuarioId' => 't.USUARIO_ID'];
        $r->search = ['u.NOME', 'u.MATRICULA', 't.NUM_DOCUMENTO'];
        $r->orderBy = 't.DATA_VENCIMENTO DESC, t.ID DESC';
        $r->createdAt = 'DATA_CADASTRO';
        $r->beforeWrite = static function (array $data, AuthUser $user, ?int $id): array {
            if (isset($data['VALOR']) && (float) $data['VALOR'] <= 0) {
                throw ApiException::validation(['valor' => 'Informe um valor maior que zero.']);
            }
            // recebido sem data de pagamento: assume hoje
            if (($data['LISTA_SITUACAO_CR_ID'] ?? null) === 2 && empty($data['DATA_PAGAMENTO'])) {
                $data['DATA_PAGAMENTO'] = date('Y-m-d');
            }
            $data['DATA_EDICAO'] = date('Y-m-d H:i:s');
            return $data;
        };
        return self::soAdmin($r);
    }

    public static function contasPagar(): Resource
    {
        $r = new Resource('CONTAS_PAGAR', 'Conta a pagar');
        $r->fields(
            F::string('DESCRICAO', 300)->required(),
            F::ref('LISTA_CATEGORIA_CP_ID', 'LISTA_CATEGORIA_CP', false),
            F::ref('LISTA_SITUACAO_CP_ID', 'LISTA_SITUACAO_CP', false)->default(1),
            F::date('DATA_VENCIMENTO')->required(),
            F::date('DATA_PAGAMENTO'),
            F::decimal('VALOR')->required(),
        );
        $r->joins = ['LEFT JOIN LISTA_SITUACAO_CP s ON s.ID = t.LISTA_SITUACAO_CP_ID', 'LEFT JOIN LISTA_CATEGORIA_CP c ON c.ID = t.LISTA_CATEGORIA_CP_ID'];
        $r->extraSelect = ['s.VALOR AS SITUACAO', 'c.VALOR AS CATEGORIA'];
        $r->filters = ['situacaoId' => 't.LISTA_SITUACAO_CP_ID', 'categoriaId' => 't.LISTA_CATEGORIA_CP_ID'];
        $r->search = ['t.DESCRICAO'];
        $r->orderBy = 't.DATA_VENCIMENTO DESC, t.ID DESC';
        return self::soAdmin($r);
    }

    /** Despesas recorrentes: geram contas a pagar mês a mês (CobrancaService::gerarContasFixas). */
    public static function contasFixas(): Resource
    {
        $r = new Resource('CONTAS_FIXAS', 'Conta fixa');
        $r->fields(
            F::string('DESCRICAO', 300)->required(),
            F::ref('LISTA_CATEGORIA_CP_ID', 'LISTA_CATEGORIA_CP', false),
            F::int('DIA_VENCIMENTO')->required(),
            F::decimal('VALOR')->required(),
        );
        $r->joins = ['LEFT JOIN LISTA_CATEGORIA_CP c ON c.ID = t.LISTA_CATEGORIA_CP_ID'];
        $r->extraSelect = ['c.VALOR AS CATEGORIA'];
        $r->search = ['t.DESCRICAO'];
        $r->orderBy = 't.DIA_VENCIMENTO, t.DESCRICAO';
        $r->beforeWrite = static function (array $data): array {
            if (isset($data['DIA_VENCIMENTO']) && ($data['DIA_VENCIMENTO'] < 1 || $data['DIA_VENCIMENTO'] > 31)) {
                throw ApiException::validation(['diaVencimento' => 'Dia entre 1 e 31.']);
            }
            return $data;
        };
        return self::soAdmin($r);
    }

    /**
     * Conta de recebimento / gateway. GATEWAY_TOKEN_PROD é GRAVÁVEL mas nunca devolvido
     * (a resposta só diz se está configurado). Enquanto o legado estiver no ar o token
     * continua em texto no banco, porque o sistema antigo o lê assim.
     */
    public static function contasBancarias(): Resource
    {
        $r = new Resource('CONTA_BANCARIA', 'Conta de recebimento');
        $r->fields(
            F::enum('BANCO', self::GATEWAYS)->required(),
            F::string('TITULAR', 200),
            F::string('CNPJ', 20),
            F::text('INSTRUCOES'),
            F::string('PAGSEGURO_EMAIL', 200),
            F::bool('AUTORIZAR'),
        );
        // o token entra pelo campo "gatewayToken" (tratado em beforeWrite) e nunca é selecionado
        $r->extraSelect = ["(t.GATEWAY_TOKEN_PROD IS NOT NULL AND t.GATEWAY_TOKEN_PROD <> '') AS TOKEN_CONFIGURADO"];
        $r->orderBy = 't.AUTORIZAR DESC, t.BANCO';
        $r->createDefaults = ['DOCUMENTO' => 0, 'NOSSO_NUMERO' => 0, 'ARQUIVO_REMESSA_NUM' => 0];
        return self::soAdmin($r);
    }
}
