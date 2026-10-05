<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Auth\TokenService;
use App\Support\ApiException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/** Exige "Authorization: Bearer <jwt>" e injeta o AuthUser no atributo "user". */
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly TokenService $tokens)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $header = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            throw ApiException::unauthorized();
        }
        return $handler->handle($request->withAttribute('user', $this->tokens->parse($m[1])));
    }
}
