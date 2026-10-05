<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Validação simples e explícita. Uso:
 *   $data = Validator::make($body)->required('email')->email('email')->min('senha', 8)->validate();
 */
final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];

    private function __construct(private readonly array $data)
    {
    }

    public static function make(mixed $data): self
    {
        return new self(is_array($data) ? $data : []);
    }

    public function required(string ...$fields): self
    {
        foreach ($fields as $f) {
            $v = $this->data[$f] ?? null;
            if ($v === null || (is_string($v) && trim($v) === '')) {
                $this->errors[$f] ??= 'Campo obrigatório.';
            }
        }
        return $this;
    }

    public function email(string $field): self
    {
        $v = $this->data[$field] ?? null;
        if ($v !== null && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] ??= 'E-mail inválido.';
        }
        return $this;
    }

    public function min(string $field, int $length): self
    {
        $v = $this->data[$field] ?? null;
        if (is_string($v) && $v !== '' && mb_strlen($v) < $length) {
            $this->errors[$field] ??= "Mínimo de {$length} caracteres.";
        }
        return $this;
    }

    public function max(string $field, int $length): self
    {
        $v = $this->data[$field] ?? null;
        if (is_string($v) && mb_strlen($v) > $length) {
            $this->errors[$field] ??= "Máximo de {$length} caracteres.";
        }
        return $this;
    }

    public function integer(string $field): self
    {
        $v = $this->data[$field] ?? null;
        if ($v !== null && $v !== '' && filter_var($v, FILTER_VALIDATE_INT) === false) {
            $this->errors[$field] ??= 'Número inteiro inválido.';
        }
        return $this;
    }

    /** @return array<string,mixed> os dados (strings aparadas) */
    public function validate(): array
    {
        if ($this->errors) {
            throw ApiException::validation($this->errors);
        }
        return array_map(static fn ($v) => is_string($v) ? trim($v) : $v, $this->data);
    }
}
