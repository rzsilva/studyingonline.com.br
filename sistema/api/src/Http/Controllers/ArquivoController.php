<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthUser;
use App\Domain\Trilha\ArquivoUrl;
use App\Integrations\Storage\LocalStorage;
use App\Support\ApiException;
use App\Support\Audit;
use App\Support\Connection;
use App\Support\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/** Upload (admin) e download autenticado de material de apoio. */
final class ArquivoController
{
    public function __construct(
        private readonly Connection $db,
        private readonly LocalStorage $storage,
        private readonly Audit $audit,
    ) {
    }

    /** POST /arquivos/{id}/upload (multipart, campo "arquivo") */
    public function upload(Request $request, Response $response, array $args): Response
    {
        /** @var AuthUser $user */
        $user = $request->getAttribute('user');
        $row = $this->find($user, (int) $args['id']);
        $file = $request->getUploadedFiles()['arquivo'] ?? null;
        if ($file === null) {
            throw ApiException::validation(['arquivo' => 'Selecione um arquivo.']);
        }
        $rel = $this->storage->save($file, $user->instituicaoId, 'arquivos');
        $this->db->run('UPDATE ARQUIVO SET URL = ? WHERE ID = ? AND INSTITUICAO_ID = ?',
            [ArquivoUrl::PREFIX . $rel, $row['ID'], $user->instituicaoId]);
        if (str_starts_with((string) $row['URL'], ArquivoUrl::PREFIX)) {
            $this->storage->delete(substr($row['URL'], strlen(ArquivoUrl::PREFIX)));
        }
        $this->audit->log($user, 'upload', 'ARQUIVO', (int) $row['ID'], $request);
        return Json::ok($response, ['url' => ArquivoUrl::publica(ArquivoUrl::PREFIX . $rel, (int) $row['ID'])]);
    }

    /** GET /arquivos/{id}/download — qualquer usuário autenticado da instituição. */
    public function download(Request $request, Response $response, array $args): Response
    {
        $row = $this->find($request->getAttribute('user'), (int) $args['id']);
        $url = (string) $row['URL'];
        if (!str_starts_with($url, ArquivoUrl::PREFIX)) {
            throw ApiException::notFound('Este material é um link externo.');
        }
        $path = $this->storage->path(substr($url, strlen(ArquivoUrl::PREFIX)));
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $nome = preg_replace('/[^\w\- ]+/u', '', (string) $row['TITULO']) ?: 'arquivo';

        return $response
            ->withBody(new Stream(fopen($path, 'rb')))
            ->withHeader('Content-Type', (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream')
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Content-Disposition', 'attachment; filename="' . $nome . '.' . $ext . '"');
    }

    private function find(AuthUser $user, int $id): array
    {
        $row = $this->db->run('SELECT ID, TITULO, URL FROM ARQUIVO WHERE ID = ? AND INSTITUICAO_ID = ?',
            [$id, $user->instituicaoId])->fetch();
        if (!$row) {
            throw ApiException::notFound();
        }
        return $row;
    }
}
