<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Support\Connection;

/** Guarda só o SHA-256 do refresh token; o token em si vive apenas no cookie HttpOnly. */
final class RefreshTokenRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function create(int $usuarioId, string $token, int $ttl, ?string $userAgent, ?string $ip): void
    {
        $st = $this->db->prepare(
            'INSERT INTO REFRESH_TOKEN (USUARIO_ID, TOKEN_HASH, EXPIRA_EM, USER_AGENT, IP, CRIADO_EM)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?, ?, NOW())'
        );
        $st->execute([$usuarioId, hash('sha256', $token), $ttl, mb_substr((string) $userAgent, 0, 255), $ip]);
    }

    /** @return array{ID:int,USUARIO_ID:int,REVOGADO_EM:?string,EXPIRADO:int}|null */
    public function find(string $token): ?array
    {
        $st = $this->db->prepare(
            'SELECT ID, USUARIO_ID, REVOGADO_EM, (EXPIRA_EM < NOW()) AS EXPIRADO
               FROM REFRESH_TOKEN WHERE TOKEN_HASH = ?'
        );
        $st->execute([hash('sha256', $token)]);
        return $st->fetch() ?: null;
    }

    public function revoke(int $id): void
    {
        $this->db->prepare('UPDATE REFRESH_TOKEN SET REVOGADO_EM = NOW() WHERE ID = ? AND REVOGADO_EM IS NULL')
            ->execute([$id]);
    }

    public function revokeAllForUser(int $usuarioId): void
    {
        $this->db->prepare('UPDATE REFRESH_TOKEN SET REVOGADO_EM = NOW() WHERE USUARIO_ID = ? AND REVOGADO_EM IS NULL')
            ->execute([$usuarioId]);
    }
}
