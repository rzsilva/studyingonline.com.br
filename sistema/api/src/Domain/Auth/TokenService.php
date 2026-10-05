<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Support\ApiException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

final class TokenService
{
    private const ALG = 'HS256';
    private const ISSUER = 'studyingonline';

    public function __construct(private readonly string $secret, private readonly int $ttl)
    {
        if (strlen($secret) < 32) {
            throw new \RuntimeException('JWT_SECRET ausente ou curto (mínimo 32 caracteres).');
        }
    }

    public function issue(AuthUser $user): string
    {
        $now = time();
        return JWT::encode([
            'iss'  => self::ISSUER,
            'sub'  => (string) $user->id,
            'inst' => $user->instituicaoId,
            'prf'  => $user->perfilId,
            'mst'  => $user->master,
            'iat'  => $now,
            'exp'  => $now + $this->ttl,
        ], $this->secret, self::ALG);
    }

    public function parse(string $jwt): AuthUser
    {
        try {
            $c = JWT::decode($jwt, new Key($this->secret, self::ALG));
        } catch (\Throwable) {
            throw ApiException::unauthorized('Sessão expirada ou inválida.');
        }
        if (($c->iss ?? null) !== self::ISSUER) {
            throw ApiException::unauthorized('Sessão inválida.');
        }
        return new AuthUser((int) $c->sub, (int) $c->inst, (int) $c->prf, (bool) $c->mst);
    }

    public function ttl(): int
    {
        return $this->ttl;
    }
}
