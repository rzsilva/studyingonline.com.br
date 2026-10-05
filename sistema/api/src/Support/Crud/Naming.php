<?php

declare(strict_types=1);

namespace App\Support\Crud;

final class Naming
{
    /** DATA_INICIO -> dataInicio ; NOTA1 -> nota1 */
    public static function toCamel(string $column): string
    {
        return lcfirst(str_replace(' ', '', ucwords(strtolower(str_replace('_', ' ', $column)))));
    }

    /** Converte as chaves de uma linha do banco para camelCase e tipa valores numéricos/booleanos. */
    public static function row(array $row, array $boolColumns = []): array
    {
        $out = [];
        foreach ($row as $col => $value) {
            if (in_array($col, $boolColumns, true) && $value !== null) {
                $value = (bool) $value;
            }
            $out[self::toCamel((string) $col)] = $value;
        }
        return $out;
    }

    public static function rows(array $rows, array $boolColumns = []): array
    {
        return array_map(static fn ($r) => self::row($r, $boolColumns), $rows);
    }
}
