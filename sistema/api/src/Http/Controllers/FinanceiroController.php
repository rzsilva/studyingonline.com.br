<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthUser;
use App\Domain\Financeiro\CobrancaService;
use App\Domain\Financeiro\Resources;
use App\Support\ApiException;
use App\Support\Audit;
use App\Support\Connection;
use App\Support\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Fase 5: cobrança, baixa, geração em lote, relatórios e webhook. */
final class FinanceiroController
{
    public function __construct(
        private readonly CobrancaService $cobranca,
        private readonly Connection $db,
        private readonly Audit $audit,
    ) {
    }

    private static function user(Request $r): AuthUser
    {
        return $r->getAttribute('user');
    }

    private static function admin(Request $r): AuthUser
    {
        $u = self::user($r);
        if (!$u->isAdmin()) {
            throw ApiException::forbidden();
        }
        return $u;
    }

    private static function body(Request $r): array
    {
        return (array) $r->getParsedBody();
    }

    /** POST /contas-receber/{id}/pagar {forma} — aluno (próprio título) ou admin. */
    public function pagar(Request $request, Response $response, array $args): Response
    {
        $forma = (string) (self::body($request)['forma'] ?? 'boleto');
        return Json::ok($response, $this->cobranca->pagar(self::user($request), (int) $args['id'], $forma));
    }

    /** GET /me/formas-pagamento */
    public function formas(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->cobranca->formasDisponiveis(self::user($request)->instituicaoId));
    }

    /** POST /contas-receber/{id}/baixa {dataPagamento?, observacao?} */
    public function baixa(Request $request, Response $response, array $args): Response
    {
        $b = self::body($request);
        $this->cobranca->baixaManual(self::admin($request), (int) $args['id'], $b['dataPagamento'] ?? null, $b['observacao'] ?? null);
        return Json::noContent($response);
    }

    /** POST /contas-receber/{id}/cancelar {motivo} */
    public function cancelar(Request $request, Response $response, array $args): Response
    {
        $this->cobranca->cancelar(self::admin($request), (int) $args['id'], (string) (self::body($request)['motivo'] ?? ''));
        return Json::noContent($response);
    }

    /** POST /financeiro/sincronizar — consulta os provedores e dá baixa nos pagos */
    public function sincronizar(Request $request, Response $response): Response
    {
        $u = self::admin($request);
        return Json::ok($response, $this->cobranca->sincronizar($u->instituicaoId, $u));
    }

    /** POST /financeiro/mensalidades {competencia: "AAAA-MM", simular?: bool} */
    public function mensalidades(Request $request, Response $response): Response
    {
        $b = self::body($request);
        return Json::ok($response, $this->cobranca->gerarMensalidades(self::admin($request), (string) ($b['competencia'] ?? ''),
            filter_var($b['simular'] ?? false, FILTER_VALIDATE_BOOLEAN)));
    }

    /** POST /financeiro/contas-fixas/gerar {competencia} */
    public function gerarFixas(Request $request, Response $response): Response
    {
        $n = $this->cobranca->gerarContasFixas(self::admin($request), (string) (self::body($request)['competencia'] ?? ''));
        return Json::ok($response, ['geradas' => $n]);
    }

    /** GET /financeiro/resumo?de=&ate= */
    public function resumo(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        return Json::ok($response, $this->cobranca->resumo(self::admin($request)->instituicaoId,
            (string) ($q['de'] ?? date('Y-01-01')), (string) ($q['ate'] ?? date('Y-12-31'))));
    }

    /** GET /financeiro/receber/exportar?de=&ate= — planilha (CSV ; UTF-8 com BOM, abre no Excel) */
    public function exportar(Request $request, Response $response): Response
    {
        $u = self::admin($request);
        $q = $request->getQueryParams();
        $rows = $this->cobranca->exportarReceber($u->instituicaoId, (string) ($q['de'] ?? date('Y-01-01')), (string) ($q['ate'] ?? date('Y-12-31')));
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['ID', 'Aluno', 'Matrícula', 'Categoria', 'Vencimento', 'Pagamento', 'Valor', 'Situação'], ';');
        foreach ($rows as $r) {
            // neutraliza fórmulas (CSV injection) em campos de texto
            $txt = static fn ($v) => preg_match('/^[=+\-@]/', (string) $v) ? "'" . $v : $v;
            fputcsv($fh, [$r['ID'], $txt($r['NOME']), $txt($r['MATRICULA']), $r['CATEGORIA'], substr((string) $r['DATA_VENCIMENTO'], 0, 10),
                substr((string) $r['DATA_PAGAMENTO'], 0, 10), number_format((float) $r['VALOR'], 2, ',', ''), $r['SITUACAO']], ';');
        }
        rewind($fh);
        $response->getBody()->write((string) stream_get_contents($fh));
        $this->audit->log($u, 'exportar', 'CONTAS_RECEBER', null, $request, ['linhas' => count($rows)]);
        return $response->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="contas-a-receber.csv"');
    }

    /** PUT /contas-bancarias/{id}/token {token} — grava a credencial do gateway (nunca é devolvida). */
    public function token(Request $request, Response $response, array $args): Response
    {
        $u = self::admin($request);
        $token = trim((string) (self::body($request)['token'] ?? ''));
        if ($token === '' || mb_strlen($token) > 500) {
            throw ApiException::validation(['token' => 'Informe a credencial do provedor.']);
        }
        $n = $this->db->run('UPDATE CONTA_BANCARIA SET GATEWAY_TOKEN_PROD = ? WHERE ID = ? AND INSTITUICAO_ID = ?',
            [$token, (int) $args['id'], $u->instituicaoId])->rowCount();
        if ($n === 0 && !$this->db->run('SELECT 1 FROM CONTA_BANCARIA WHERE ID = ? AND INSTITUICAO_ID = ?', [(int) $args['id'], $u->instituicaoId])->fetchColumn()) {
            throw ApiException::notFound();
        }
        $this->audit->log($u, 'alterar_credencial', 'CONTA_BANCARIA', (int) $args['id'], $request);
        return Json::noContent($response);
    }

    /** GET /contas-bancarias/{id}/webhook — URL a cadastrar no painel do MercadoPago */
    public function webhookUrl(Request $request, Response $response, array $args): Response
    {
        $u = self::admin($request);
        return Json::ok($response, ['url' => $this->cobranca->webhookUrl($u->instituicaoId)]);
    }

    /** POST /webhooks/mercadopago/{instituicao}/{token} — público, validado por HMAC + consulta à API */
    public function webhookMercadoPago(Request $request, Response $response, array $args): Response
    {
        $corpo = (array) $request->getParsedBody() + $request->getQueryParams();
        $r = $this->cobranca->webhookMercadoPago((int) $args['instituicao'], (string) $args['token'], $corpo);
        return Json::ok($response, ['resultado' => $r]);
    }

    /** lista de gateways aceitos (para o cadastro de contas) */
    public function gateways(Request $request, Response $response): Response
    {
        self::admin($request);
        return Json::ok($response, array_map(static fn ($g) => ['id' => $g, 'nome' => $g], Resources::GATEWAYS));
    }
}
