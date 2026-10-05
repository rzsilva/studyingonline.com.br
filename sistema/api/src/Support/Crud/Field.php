<?php

declare(strict_types=1);

namespace App\Support\Crud;

/**
 * Definição de uma coluna gravável. O nome no JSON é o camelCase da coluna
 * (DATA_INICIO -> dataInicio). Colunas não declaradas nunca são gravadas.
 */
final class Field
{
    public bool $required = false;
    public mixed $default = null;
    public ?int $max = null;
    /** @var string[]|null */
    public ?array $enum = null;
    /** tabela referenciada (FK) */
    public ?string $refTable = null;
    /** a tabela referenciada tem INSTITUICAO_ID (precisa ser da mesma instituição) */
    public bool $refTenant = true;

    private function __construct(public readonly string $column, public readonly string $type)
    {
    }

    public static function string(string $col, int $max = 255): self
    {
        $f = new self($col, 'string');
        $f->max = $max;
        return $f;
    }

    public static function text(string $col): self
    {
        $f = new self($col, 'string');
        $f->max = 65535;
        return $f;
    }

    public static function url(string $col): self
    {
        $f = new self($col, 'url');
        $f->max = 1000;
        return $f;
    }

    public static function int(string $col): self
    {
        return new self($col, 'int');
    }

    public static function decimal(string $col): self
    {
        return new self($col, 'decimal');
    }

    public static function bool(string $col): self
    {
        $f = new self($col, 'bool');
        $f->default = 0;
        return $f;
    }

    public static function date(string $col): self
    {
        return new self($col, 'date');
    }

    public static function time(string $col): self
    {
        return new self($col, 'time');
    }

    /** @param string[] $values */
    public static function enum(string $col, array $values): self
    {
        $f = new self($col, 'enum');
        $f->enum = $values;
        return $f;
    }

    /** FK para tabela da mesma instituição (ou global, com $tenant=false). */
    public static function ref(string $col, string $table, bool $tenant = true): self
    {
        $f = new self($col, 'ref');
        $f->refTable = $table;
        $f->refTenant = $tenant;
        return $f;
    }

    public function required(): self
    {
        $this->required = true;
        return $this;
    }

    public function default(mixed $value): self
    {
        $this->default = $value;
        return $this;
    }

    public function key(): string
    {
        return Naming::toCamel($this->column);
    }
}
