<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\PasswordResetService;
use App\Domain\Instituicao\InstituicaoResolver;
use App\Support\ApiException;
use App\Support\Json;
use App\Support\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteContext;

final class AuthController
{
    private const COOKIE = 'so_refresh';

    public function __construct(
        private readonly AuthService $auth,
        private readonly PasswordResetService $reset,
        private readonly InstituicaoResolver $resolver,
        private readonly int $refreshTtl,
        private readonly bool $cookieSecure,
    ) {
    }

    /** POST /auth/login {email, senha} */
    public function login(Request $request, Response $response): Response
    {
        $data = Validator::make($request->getParsedBody())
            ->required('email', 'senha')->email('email')->max('senha', 128)
            ->validate();

        $session = $this->auth->login(
            $data['email'],
            (string) $request->getParsedBody()['senha'], // senha sem trim
            $this->instituicaoId($request),
            self::ip($request),
            $request->getHeaderLine('User-Agent') ?: null,
        );
        return $this->sessionResponse($request, $response, $session);
    }

    /** POST /auth/refresh (usa o cookie HttpOnly) */
    public function refresh(Request $request, Response $response): Response
    {
        $token = $request->getCookieParams()[self::COOKIE] ?? '';
        if ($token === '') {
            throw ApiException::unauthorized('Sessão expirada.');
        }
        $session = $this->auth->refresh($token, self::ip($request), $request->getHeaderLine('User-Agent') ?: null);
        return $this->sessionResponse($request, $response, $session);
    }

    /** POST /auth/logout */
    public function logout(Request $request, Response $response): Response
    {
        $this->auth->logout($request->getCookieParams()[self::COOKIE] ?? null);
        return Json::noContent($response->withAddedHeader('Set-Cookie', $this->cookie($request, '', -3600)));
    }

    /** POST /auth/esqueci-senha {email} */
    public function forgot(Request $request, Response $response): Response
    {
        $data = Validator::make($request->getParsedBody())->required('email')->email('email')->validate();
        $this->reset->request($data['email'], $this->instituicaoId($request), self::ip($request));
        return Json::ok($response, ['message' => 'Se o e-mail estiver cadastrado, você receberá um link para redefinir a senha.']);
    }

    /** POST /auth/redefinir-senha {token, novaSenha} */
    public function resetPassword(Request $request, Response $response): Response
    {
        $data = Validator::make($request->getParsedBody())->required('token', 'novaSenha')->validate();
        $this->reset->reset($data['token'], (string) $request->getParsedBody()['novaSenha']);
        return Json::ok($response, ['message' => 'Senha alterada. Faça login com a nova senha.']);
    }

    private function sessionResponse(Request $request, Response $response, array $session): Response
    {
        $response = $response->withAddedHeader('Set-Cookie', $this->cookie($request, $session['refresh_token'], $this->refreshTtl));
        return Json::ok($response, [
            'accessToken' => $session['access_token'],
            'expiresIn'   => $session['expires_in'],
        ]);
    }

    /** Cookie restrito às rotas /auth da API (respeita a subpasta onde a API está publicada). */
    private function cookie(Request $request, string $value, int $ttl): string
    {
        $parts = [
            self::COOKIE . '=' . rawurlencode($value),
            'Path=' . RouteContext::fromRequest($request)->getBasePath() . '/auth',
            'Max-Age=' . $ttl,
            'HttpOnly',
            'SameSite=Strict',
        ];
        if ($this->cookieSecure) {
            $parts[] = 'Secure';
        }
        return implode('; ', $parts);
    }

    private function instituicaoId(Request $request): ?int
    {
        $host = $request->getHeaderLine('X-Instituicao-Host');
        if ($host === '') {
            return null;
        }
        $inst = $this->resolver->resolve($host);
        return $inst ? (int) $inst['ID'] : null;
    }

    public static function ip(Request $request): string
    {
        return (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
