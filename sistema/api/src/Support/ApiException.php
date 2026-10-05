<?php

declare(strict_types=1);

namespace App\Support;

/** Erro de negócio com status HTTP; a mensagem é segura para exibir ao usuário. */
final class ApiException extends \RuntimeException
{
    /** @param array<string,string> $fields erros de validação por campo */
    public function __construct(
        string $message,
        public readonly int $status = 400,
        public readonly string $errorCode = 'bad_request',
        public readonly array $fields = [],
    ) {
        parent::__construct($message, $status);
    }

    public static function notFound(string $message = 'Registro não encontrado.'): self
    {
        return new self($message, 404, 'not_found');
    }

    public static function unauthorized(string $message = 'Não autenticado.'): self
    {
        return new self($message, 401, 'unauthorized');
    }

    public static function forbidden(string $message = 'Acesso negado.'): self
    {
        return new self($message, 403, 'forbidden');
    }

    /** @param array<string,string> $fields */
    public static function validation(array $fields): self
    {
        return new self('Dados inválidos.', 422, 'validation', $fields);
    }
}
