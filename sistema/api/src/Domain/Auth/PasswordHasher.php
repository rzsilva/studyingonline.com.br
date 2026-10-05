<?php

declare(strict_types=1);

namespace App\Domain\Auth;

final class PasswordHasher
{
    public function hash(string $plain): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_hash($plain, $algo);
    }

    public function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_needs_rehash($hash, $algo);
    }

    /** Comparação em tempo constante com a senha legada em texto puro. */
    public function verifyLegacy(string $plain, ?string $stored): bool
    {
        return $stored !== null && $stored !== '' && hash_equals($stored, $plain);
    }
}
