<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ApiException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * O BodyParsingMiddleware do Slim transforma JSON inválido em corpo nulo silenciosamente,
 * o que vira "campo obrigatório" confuso. Aqui JSON malformado (ou não-UTF-8) gera 400 explícito.
 */
final class JsonBodyMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        if (str_contains(strtolower($request->getHeaderLine('Content-Type')), 'application/json')) {
            $raw = (string) $request->getBody();
            if (trim($raw) !== '') {
                try {
                    $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new ApiException('JSON inválido ou com codificação diferente de UTF-8.', 400, 'invalid_json');
                }
                if (!is_array($data)) {
                    throw new ApiException('O corpo da requisição deve ser um objeto JSON.', 400, 'invalid_json');
                }
                $request = $request->withParsedBody($data);
            }
        }
        return $handler->handle($request);
    }
}
