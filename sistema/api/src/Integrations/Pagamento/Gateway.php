<?php

declare(strict_types=1);

namespace App\Integrations\Pagamento;

/** Contrato comum dos meios de cobrança (BoletoCloud, Vindi, PagSeguro, MercadoPago). */
interface Gateway
{
    /** Nome em CONTA_BANCARIA.BANCO. */
    public function nome(): string;

    /** Cria a cobrança no provedor. */
    public function emitir(Titulo $titulo, ContaGateway $conta): Emissao;

    /** Consulta a situação atual de um título já emitido. */
    public function consultar(Titulo $titulo, ContaGateway $conta): Situacao;

    /** URL da 2ª via a partir dos tokens já gravados (sem chamar o provedor). */
    public function urlSegundaVia(Titulo $titulo): ?string;
}
