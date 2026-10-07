<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Adaline\AdalineService;
use App\Domain\Auth\AuthUser;
use App\Support\ApiException;
use App\Support\Audit;
use App\Support\Connection;
use App\Support\Crud\Cpf;
use App\Support\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Fase 6: dados da instituição, faturas da Adaline (escola e operadores) e extrato de boletos. */
final class AdalineController
{
    /** Campos que o administrador da escola pode editar (contrato/plano/URL só a Adaline). */
    private const CAMPOS_ESCOLA = [
        'fantasia' => ['FANTASIA', 200], 'titulo' => ['TITULO', 200], 'razaoSocial' => ['RAZAO_SOCIAL', 200], 'cnpj' => ['CNPJ', 20],
        'email' => ['EMAIL', 200], 'emailCobranca' => ['EMAIL_COBRANCA', 200], 'telefone' => ['TELEFONE', 30], 'celular' => ['CELULAR', 30],
        'cep' => ['CEP', 10], 'uf' => ['UF', 2], 'cidade' => ['CIDADE', 100], 'bairro' => ['BAIRRO', 100], 'rua' => ['RUA', 200],
        'numero' => ['NUMERO', 20], 'logo' => ['LOGO', 500], 'corPrimaria' => ['COR_PRIMARIA', 7],
    ];

    public function __construct(
        private readonly AdalineService $adaline,
        private readonly Connection $db,
        private readonly Audit $audit,
    ) {
    }

    private static function user(Request $r): AuthUser
    {
        return $r->getAttribute('user');
    }

    private static function body(Request $r): array
    {
        return (array) $r->getParsedBody();
    }

    /* ---------- Instituição (admin da escola) ---------- */

    /** GET /instituicao */
    public function minhaInstituicao(Request $request, Response $response): Response
    {
        $u = self::user($request);
        if (!$u->isAdmin()) {
            throw ApiException::forbidden();
        }
        $i = $this->db->run('SELECT * FROM INSTITUICAO WHERE ID = ?', [$u->instituicaoId])->fetch();
        $out = [];
        foreach (self::CAMPOS_ESCOLA as $k => [$col]) {
            $out[$k] = $i[$col] ?? null;
        }
        $out += ['url' => $i['URL'], 'plano' => $i['PLANO'], 'alunosQtdMax' => $i['ALUNOS_QTD_MAX'], 'vencimento' => $i['DATA_VENCIMENTO'],
            'cobrarBoletos' => (bool) $i['COBRAR_BOLETOS'], 'ativo' => (bool) $i['ATIVO'], 'operadorAdaline' => $this->adaline->ehOperador($u)];
        return Json::ok($response, $out);
    }

    /** PUT /instituicao */
    public function salvarInstituicao(Request $request, Response $response): Response
    {
        $u = self::user($request);
        if (!$u->isAdmin()) {
            throw ApiException::forbidden();
        }
        $b = self::body($request);
        $sets = [];
        $vals = [];
        $erros = [];
        foreach (self::CAMPOS_ESCOLA as $k => [$col, $max]) {
            if (!array_key_exists($k, $b)) {
                continue;
            }
            $v = $b[$k] === null ? null : trim((string) $b[$k]);
            $v = $v === '' ? null : $v;
            if ($v !== null && mb_strlen($v) > $max) {
                $erros[$k] = "Máximo de {$max} caracteres.";
            } elseif ($v !== null && in_array($k, ['email', 'emailCobranca'], true) && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                $erros[$k] = 'E-mail inválido.';
            } elseif ($v !== null && $k === 'corPrimaria' && !preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $v)) {
                $erros[$k] = 'Use uma cor no formato #RRGGBB.';
            } elseif ($v !== null && $k === 'logo' && !preg_match('#^https://#i', $v)) {
                $erros[$k] = 'Use um endereço https:// para o logo.';
            } elseif ($k === 'fantasia' && $v === null) {
                $erros[$k] = 'Campo obrigatório.';
            }
            if ($k === 'uf' && $v !== null) {
                $v = strtoupper($v);
            }
            $sets[] = "{$col} = ?";
            $vals[] = $v;
        }
        if ($erros) {
            throw ApiException::validation($erros);
        }
        if ($sets) {
            $this->db->run('UPDATE INSTITUICAO SET ' . implode(', ', $sets) . ', DATA_EDICAO = NOW() WHERE ID = ?', [...$vals, $u->instituicaoId]);
            $this->audit->log($u, 'alterar', 'INSTITUICAO', $u->instituicaoId, $request);
        }
        return Json::noContent($response);
    }

    /* ---------- Faturas da Adaline (escola) ---------- */

    /** GET /adaline/faturas — admin da escola: as próprias; operador: todas (?instituicaoId=) */
    public function faturas(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        return Json::ok($response, $this->adaline->faturas(self::user($request), isset($q['instituicaoId']) ? (int) $q['instituicaoId'] : null));
    }

    /** GET /adaline/extrato — boletos emitidos pela escola */
    public function extrato(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->adaline->extrato(self::user($request)));
    }

    /* ---------- Painel da Adaline (operadores) ---------- */

    public function instituicoes(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->adaline->instituicoes(self::user($request)));
    }

    public function contrato(Request $request, Response $response, array $args): Response
    {
        $this->adaline->atualizarContrato(self::user($request), (int) $args['id'], self::body($request));
        return Json::noContent($response);
    }

    public function sugestao(Request $request, Response $response, array $args): Response
    {
        return Json::ok($response, $this->adaline->sugestao(self::user($request), (int) $args['id']));
    }

    public function criarFatura(Request $request, Response $response): Response
    {
        return Json::ok($response, ['id' => $this->adaline->criarFatura(self::user($request), self::body($request))], [], 201);
    }

    public function emitir(Request $request, Response $response, array $args): Response
    {
        return Json::ok($response, ['url' => $this->adaline->emitirBoleto(self::user($request), (int) $args['id'])]);
    }

    public function cancelar(Request $request, Response $response, array $args): Response
    {
        $this->adaline->cancelarFatura(self::user($request), (int) $args['id']);
        return Json::noContent($response);
    }

    public function sincronizar(Request $request, Response $response): Response
    {
        $this->adaline->exigirOperador(self::user($request));
        return Json::ok($response, ['pagas' => $this->adaline->sincronizar()]);
    }
}
