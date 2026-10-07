<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthUser;
use App\Domain\Auth\PasswordResetService;
use App\Domain\Instituicao\InstituicaoResolver;
use App\Domain\Secretaria\InscricaoService;
use App\Support\ApiException;
use App\Support\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/** Fase 4: inscrição pública, análise de inscrições, convites, rematrícula e rotinas. */
final class SecretariaController
{
    public function __construct(
        private readonly InscricaoService $inscricoes,
        private readonly InstituicaoResolver $resolver,
        private readonly PasswordResetService $reset,
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

    /** Instituição do subdomínio (header X-Instituicao-Host). Nunca vem do corpo da requisição. */
    private function instituicaoDoHost(Request $r): int
    {
        $host = $r->getHeaderLine('X-Instituicao-Host');
        $inst = $host !== '' ? $this->resolver->resolve($host) : null;
        if (!$inst || !(bool) $inst['ATIVO']) {
            throw ApiException::notFound('Instituição não encontrada para este endereço.');
        }
        return (int) $inst['ID'];
    }

    /* ---------- público ---------- */

    /** GET /publico/cursos */
    public function cursosAbertos(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->inscricoes->cursosAbertos($this->instituicaoDoHost($request)));
    }

    /** POST /publico/inscricoes */
    public function inscrever(Request $request, Response $response): Response
    {
        $res = $this->inscricoes->inscrever(
            $this->instituicaoDoHost($request),
            (array) $request->getParsedBody(),
            AuthController::ip($request),
        );
        return Json::ok($response, $res, [], 201);
    }

    /** POST /publico/inscricoes/documentos/{campo}  (Authorization: Documento <token>) */
    public function documentoPublico(Request $request, Response $response, array $args): Response
    {
        $token = preg_replace('/^Documento\s+/i', '', $request->getHeaderLine('Authorization'));
        [$inscricaoId, $instituicaoId] = $this->inscricoes->validarTokenDocumentos((string) $token);
        $this->inscricoes->salvarDocumento($inscricaoId, $instituicaoId, $args['campo'], $this->arquivo($request));
        return Json::noContent($response);
    }

    /* ---------- aluno ---------- */

    /** GET /me/inscricao */
    public function minhaInscricao(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->inscricoes->minhaInscricao(self::user($request)));
    }

    /** POST /me/inscricao/documentos/{campo} */
    public function meuDocumento(Request $request, Response $response, array $args): Response
    {
        $user = self::user($request);
        $ins = $this->inscricoes->minhaInscricao($user);
        if (!$ins) {
            throw ApiException::notFound('Você não possui inscrição.');
        }
        $this->inscricoes->salvarDocumento($ins['id'], $user->instituicaoId, $args['campo'], $this->arquivo($request));
        return Json::noContent($response);
    }

    /** GET /me/cursos-abertos — cursos em que o aluno logado pode se inscrever */
    public function cursosParaAluno(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->inscricoes->cursosAbertos(self::user($request)->instituicaoId));
    }

    /** POST /me/inscricoes {cursoId} */
    public function inscreverLogado(Request $request, Response $response): Response
    {
        $cursoId = (int) (((array) $request->getParsedBody())['cursoId'] ?? 0);
        return Json::ok($response, $this->inscricoes->inscreverLogado(self::user($request), $cursoId), [], 201);
    }

    /** GET /me/rematricula */
    public function rematriculas(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->inscricoes->rematriculasDisponiveis(self::user($request)));
    }

    /** POST /me/rematricula {cursoId} */
    public function rematricular(Request $request, Response $response): Response
    {
        $cursoId = (int) (((array) $request->getParsedBody())['cursoId'] ?? 0);
        return Json::ok($response, $this->inscricoes->rematricular(self::user($request), $cursoId));
    }

    /* ---------- secretaria ---------- */

    /** GET /inscricoes */
    public function listar(Request $request, Response $response): Response
    {
        [$rows, $meta] = $this->inscricoes->listar(self::admin($request), $request->getQueryParams());
        return Json::ok($response, $rows, $meta);
    }

    /** GET /inscricoes/{id} */
    public function detalhe(Request $request, Response $response, array $args): Response
    {
        return Json::ok($response, $this->inscricoes->detalhe(self::admin($request), (int) $args['id']));
    }

    /** PUT /inscricoes/{id}/status {status, justificativa} */
    public function decidir(Request $request, Response $response, array $args): Response
    {
        $b = (array) $request->getParsedBody();
        return Json::ok($response, $this->inscricoes->decidir(self::admin($request), (int) $args['id'],
            (int) ($b['status'] ?? 0), isset($b['justificativa']) ? (string) $b['justificativa'] : null));
    }

    /** GET /inscricoes/{id}/documentos/{campo} — admin ou o próprio aluno */
    public function baixarDocumento(Request $request, Response $response, array $args): Response
    {
        $doc = $this->inscricoes->documento(self::user($request), (int) $args['id'], $args['campo']);
        if (isset($doc['url'])) {
            return Json::ok($response, ['url' => $doc['url']]); // arquivo antigo (FTP do legado)
        }
        $ext = pathinfo($doc['path'], PATHINFO_EXTENSION);
        return $response->withBody(new Stream(fopen($doc['path'], 'rb')))
            ->withHeader('Content-Type', (new \finfo(FILEINFO_MIME_TYPE))->file($doc['path']) ?: 'application/octet-stream')
            ->withHeader('Content-Disposition', 'attachment; filename="' . str_replace(' ', '-', $doc['nome']) . ".{$ext}\"");
    }

    /** POST /usuarios/{id}/convite — envia link para o usuário criar a senha */
    public function convidar(Request $request, Response $response, array $args): Response
    {
        $email = $this->reset->convidar(self::admin($request), (int) $args['id']);
        return Json::ok($response, ['message' => "Convite enviado para {$email}."]);
    }

    /** POST /rotinas/inatividade — executa a rotina de inatividade da instituição */
    public function rotinaInatividade(Request $request, Response $response): Response
    {
        $n = $this->inscricoes->rotinaInatividade(self::admin($request)->instituicaoId);
        return Json::ok($response, ['inativados' => $n]);
    }

    private function arquivo(Request $request): \Psr\Http\Message\UploadedFileInterface
    {
        $f = $request->getUploadedFiles()['arquivo'] ?? null;
        if ($f === null) {
            throw ApiException::validation(['arquivo' => 'Selecione um arquivo.']);
        }
        return $f;
    }
}
