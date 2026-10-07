<?php

declare(strict_types=1);

namespace App\Integrations\Pagamento;

final class Emissao
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $tokenBoleto = null,
        public readonly ?string $tokenCartao = null,
        public readonly ?string $numDocumento = null,
    ) {
    }
}
