<?php

declare(strict_types=1);

namespace App\Integrations\Pagamento;

final class Situacao
{
    public const PENDENTE = 'pendente';
    public const PAGO = 'pago';
    public const CANCELADO = 'cancelado';

    public function __construct(
        public readonly string $status,
        public readonly ?string $dataPagamento = null, // Y-m-d
        public readonly ?string $eventoId = null,
    ) {
    }
}
