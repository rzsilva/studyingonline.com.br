<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/** Valores de LISTA_PERFIL.ID usados no sistema legado. */
enum Perfil: int
{
    case Administrador = 1;
    case Professor = 2;
    case Aluno = 3;

    public static function fromId(int $id): ?self
    {
        return self::tryFrom($id);
    }
}
