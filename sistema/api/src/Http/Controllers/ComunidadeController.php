<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthUser;
use App\Domain\Comunidade\ChatService;
use App\Domain\Comunidade\ForumService;
use App\Domain\Comunidade\PresencialService;
use App\Domain\Trilha\ArquivoUrl;
use App\Integrations\Storage\LocalStorage;
use App\Support\ApiException;
use App\Support\Audit;
use App\Support\Connection;
use App\Support\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/** Fase 3: avisos (imagem), fórum, chat, painel presencial e extrato do aluno. */
final class ComunidadeController
{
    public function __construct(
        private readonly Connection $db,
        private readonly LocalStorage $storage,
        private readonly ForumService $forum,
        private readonly ChatService $chat,
        private readonly PresencialService $presencial,
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

    /* ---------- Avisos: imagem ---------- */

    /** POST /avisos/{id}/imagem (admin; multipart "arquivo"; só jpg/png) */
    public function avisoImagem(Request $request, Response $response, array $args): Response
    {
        $user = self::user($request);
        $id = (int) $args['id'];
        $aviso = $this->db->run('SELECT ID, URL FROM AVISO WHERE ID = ? AND INSTITUICAO_ID = ?', [$id, $user->instituicaoId])->fetch();
        if (!$aviso) {
            throw ApiException::notFound();
        }
        $file = $request->getUploadedFiles()['arquivo'] ?? null;
        if ($file === null) {
            throw ApiException::validation(['arquivo' => 'Selecione uma imagem.']);
        }
        $rel = $this->storage->save($file, $user->instituicaoId, 'avisos', ['jpg', 'jpeg', 'png']);
        $this->db->run('UPDATE AVISO SET URL = ? WHERE ID = ?', [ArquivoUrl::PREFIX . $rel, $id]);
        if (str_starts_with((string) $aviso['URL'], ArquivoUrl::PREFIX)) {
            $this->storage->delete(substr($aviso['URL'], strlen(ArquivoUrl::PREFIX)));
        }
        $this->audit->log($user, 'upload', 'AVISO', $id, $request);
        return Json::ok($response, ['url' => "/avisos/{$id}/imagem"]);
    }

    /** GET /avisos/{id}/imagem — imagem enviada no sistema novo (as antigas são URLs absolutas). */
    public function avisoImagemDownload(Request $request, Response $response, array $args): Response
    {
        $user = self::user($request);
        $url = (string) $this->db->run('SELECT URL FROM AVISO WHERE ID = ? AND INSTITUICAO_ID = ?',
            [(int) $args['id'], $user->instituicaoId])->fetchColumn();
        if (!str_starts_with($url, ArquivoUrl::PREFIX)) {
            throw ApiException::notFound();
        }
        $path = $this->storage->path(substr($url, strlen(ArquivoUrl::PREFIX)));
        return $response->withBody(new Stream(fopen($path, 'rb')))
            ->withHeader('Content-Type', (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream')
            ->withHeader('Cache-Control', 'private, max-age=3600');
    }

    /* ---------- Fórum ---------- */

    public function topicos(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        [$rows, $meta] = $this->forum->topicos(self::user($request), isset($q['disciplinaId']) ? (int) $q['disciplinaId'] : null,
            trim((string) ($q['q'] ?? '')), (int) ($q['page'] ?? 1));
        return Json::ok($response, $rows, $meta);
    }

    public function topico(Request $request, Response $response, array $args): Response
    {
        return Json::ok($response, $this->forum->topico(self::user($request), (int) $args['id']));
    }

    public function criarTopico(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->forum->criarTopico(self::user($request), self::body($request)), [], 201);
    }

    public function editarTopico(Request $request, Response $response, array $args): Response
    {
        $this->forum->editarTopico(self::user($request), (int) $args['id'], self::body($request));
        return Json::noContent($response);
    }

    public function excluirTopico(Request $request, Response $response, array $args): Response
    {
        $this->forum->excluirTopico(self::user($request), (int) $args['id']);
        return Json::noContent($response);
    }

    public function responder(Request $request, Response $response, array $args): Response
    {
        return Json::ok($response, $this->forum->responder(self::user($request), (int) $args['id'], self::body($request)), [], 201);
    }

    public function editarResposta(Request $request, Response $response, array $args): Response
    {
        $this->forum->editarResposta(self::user($request), (int) $args['id'], self::body($request));
        return Json::noContent($response);
    }

    public function excluirResposta(Request $request, Response $response, array $args): Response
    {
        $this->forum->excluirResposta(self::user($request), (int) $args['id']);
        return Json::noContent($response);
    }

    /* ---------- Chat ---------- */

    public function contatos(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->chat->contatos(self::user($request)));
    }

    public function mensagens(Request $request, Response $response, array $args): Response
    {
        $depois = (int) ($request->getQueryParams()['depois'] ?? 0);
        return Json::ok($response, $this->chat->mensagens(self::user($request), (int) $args['id'], $depois));
    }

    public function enviar(Request $request, Response $response, array $args): Response
    {
        $texto = (string) (self::body($request)['texto'] ?? '');
        return Json::ok($response, $this->chat->enviar(self::user($request), (int) $args['id'], $texto), [], 201);
    }

    /** GET /chat/nao-lidas — contador para o menu (polling leve) */
    public function naoLidas(Request $request, Response $response): Response
    {
        $user = self::user($request);
        $n = (int) $this->db->run('SELECT COUNT(*) FROM CHAT_MENSAGENS WHERE USUARIO_RECEIVE_ID = ? AND LIDA_EM IS NULL', [$user->id])->fetchColumn();
        return Json::ok($response, ['naoLidas' => $n]);
    }

    /* ---------- Painel presencial / financeiro do aluno ---------- */

    public function presencialCursos(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->presencial->cursos(self::user($request)));
    }

    public function presencialCurso(Request $request, Response $response, array $args): Response
    {
        return Json::ok($response, $this->presencial->curso(self::user($request), (int) $args['id']));
    }

    public function meuFinanceiro(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->presencial->financeiro(self::user($request)));
    }
}
