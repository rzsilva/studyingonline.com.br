<?php

declare(strict_types=1);

namespace App\Support\Crud;

final class Cpf
{
    /** Valida os dígitos verificadores (rejeita sequências repetidas como 111.111.111-11). */
    public static function valido(string $cpf): bool
    {
        $cpf = preg_replace('/\D/', '', $cpf) ?? '';
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            $soma = 0;
            for ($i = 0; $i < $t; $i++) {
                $soma += (int) $cpf[$i] * (($t + 1) - $i);
            }
            $dv = ((10 * $soma) % 11) % 10;
            if ((int) $cpf[$t] !== $dv) {
                return false;
            }
        }
        return true;
    }

    public static function formatar(?string $cpf): ?string
    {
        $d = preg_replace('/\D/', '', (string) $cpf);
        return strlen((string) $d) === 11 ? vsprintf('%s%s%s.%s%s%s.%s%s%s-%s%s', str_split($d)) : $cpf;
    }
}
