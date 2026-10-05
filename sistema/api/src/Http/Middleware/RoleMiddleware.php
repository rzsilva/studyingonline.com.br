<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Auth\AuthUser;
use App\Domain\Auth\Perfil;
use App\Support\ApiException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/** Restringe a rota aos perfis informados. Uso: ->add(RoleMiddleware::only(Perfil::Administrador)) */
final class RoleMiddleware implements MiddlewareInterface
{
    /** @param Perfil[] $perfis */
    private function __construct(private readonly array $perfis)
    {
    }

    public static function only(Perfil ...$perfis): self
    {
        return new self($perfis);
    }

    public function process(Request $request, Handler $handler): Response
    {
        $user = $request->getAttribute('user');
        if (!$user instanceof AuthUser || !$user->is(...$this->perfis)) {
            throw ApiException::forbidden();
        }
        return $handler->handle($request);
    }
}
