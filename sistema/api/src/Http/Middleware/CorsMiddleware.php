<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/** CORS por lista de origens (aceita curinga de subdomínio "https://*.dominio"). */
final class CorsMiddleware implements MiddlewareInterface
{
    /** @param string[] $allowed */
    public function __construct(private readonly array $allowed, private readonly ResponseFactoryInterface $factory)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $origin = $request->getHeaderLine('Origin');
        $response = $request->getMethod() === 'OPTIONS'
            ? $this->factory->createResponse(204)
            : $handler->handle($request);

        if ($origin !== '' && $this->isAllowed($origin)) {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Access-Control-Allow-Credentials', 'true')
                ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-Instituicao-Host')
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
                ->withHeader('Access-Control-Max-Age', '600')
                ->withAddedHeader('Vary', 'Origin');
        }
        return $response;
    }

    private function isAllowed(string $origin): bool
    {
        foreach ($this->allowed as $rule) {
            if ($rule === $origin) {
                return true;
            }
            if (str_contains($rule, '*.')) {
                $pattern = '#^' . str_replace('\*', '[a-z0-9-]+', preg_quote($rule, '#')) . '$#i';
                if (preg_match($pattern, $origin)) {
                    return true;
                }
            }
        }
        return false;
    }
}
