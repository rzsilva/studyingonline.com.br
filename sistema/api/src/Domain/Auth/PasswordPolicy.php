<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Support\ApiException;

final class PasswordPolicy
{
    public const MIN_LENGTH = 8;

    public static function assert(string $senha, string $field = 'novaSenha'): void
    {
        if (mb_strlen($senha) < self::MIN_LENGTH) {
            throw ApiException::validation([$field => 'A senha deve ter pelo menos ' . self::MIN_LENGTH . ' caracteres.']);
        }
        if (mb_strlen($senha) > 128) {
            throw ApiException::validation([$field => 'Senha muito longa.']);
        }
        if (!preg_match('/[A-Za-z]/', $senha) || !preg_match('/\d/', $senha)) {
            throw ApiException::validation([$field => 'Use letras e números na senha.']);
        }
    }
}
