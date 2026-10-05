<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Trilha\PainelService;
use App\Support\ApiException;
use App\Support\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PainelController
{
    public function __construct(private readonly PainelService $painel)
    {
    }

    /** GET /painel/cursos */
    public function cursos(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->painel->meusCursos($request->getAttribute('user')));
    }

    /** GET /painel/cursos/{id} */
    public function trilha(Request $request, Response $response, array $args): Response
    {
        return Json::ok($response, $this->painel->trilha($request->getAttribute('user'), (int) $args['id']));
    }

    /** POST /painel/videos/{id}/progresso {evento: "progress"|"ended", tempo?} */
    public function progresso(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $evento = (string) ($body['evento'] ?? '');
        if (!in_array($evento, ['progress', 'ended'], true)) {
            throw ApiException::validation(['evento' => 'Use "progress" ou "ended".']);
        }
        $tempo = isset($body['tempo']) ? (string) $body['tempo'] : null;
        return Json::ok($response, $this->painel->progressoVideo($request->getAttribute('user'), (int) $args['id'], $evento, $tempo));
    }

    /** POST /painel/provas/{id}/respostas {respostas: {questaoId: "A"}, recuperacao?: bool} */
    public function responder(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();
        $respostas = $body['respostas'] ?? null;
        if (!is_array($respostas) || !$respostas) {
            throw ApiException::validation(['respostas' => 'Responda ao menos uma questão.']);
        }
        return Json::ok($response, $this->painel->responderProva(
            $request->getAttribute('user'),
            (int) $args['id'],
            $respostas,
            filter_var($body['recuperacao'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ));
    }
}
