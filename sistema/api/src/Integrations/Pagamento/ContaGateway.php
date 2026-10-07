<?php

declare(strict_types=1);

namespace App\Integrations\Pagamento;

final class ContaGateway
{
    public function __construct(
        public readonly int $id,
        public readonly string $banco,
        public readonly string $token,
        public readonly ?string $instrucoes = null,
        public readonly ?string $pagseguroEmail = null,
    ) {
    }
}
