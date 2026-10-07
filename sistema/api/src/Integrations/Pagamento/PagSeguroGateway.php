<?php

declare(strict_types=1);

namespace App\Integrations\Pagamento;

final class PagSeguroGateway implements Gateway
{
    private const SEGUNDA_VIA = 'https://pagseguro.uol.com.br/v2/checkout/payment.html?code=';

    public function __construct(private readonly HttpCliente $http, private readonly string $proxyUrl)
    {
    }

    public function nome(): string
    {
        return 'PagSeguro';
    }

    public function emitir(Titulo $t, ContaGateway $c): Emissao
    {
        $p = $t->pagador;
        $xml = $this->http->form(rtrim($this->proxyUrl, '/') . '/pagseguro/checkout.php', [
            'Debug' => 'false', 'PagSeguroEmail' => (string) $c->pagseguroEmail, 'Token' => $c->token, 'currency' => 'BRL',
            'itemId1' => '0001', 'itemDescription1' => $t->descricao, 'itemAmount1' => number_format($t->valor, 2, '.', ''),
            'itemQuantity1' => '1', 'reference' => (string) $t->id,
            'senderName' => $p['nome'], 'senderEmail' => $p['email'], 'senderCPF' => preg_replace('/\D/', '', $p['cpf']),
            'senderBornDate' => $p['dataNascimento'] ? date('d/m/Y', strtotime((string) $p['dataNascimento'])) : '',
            'shippingAddressStreet' => $p['rua'], 'shippingAddressNumber' => $p['numero'] ?: '0', 'shippingAddressDistrict' => $p['bairro'],
            'shippingAddressPostalCode' => preg_replace('/\D/', '', $p['cep']), 'shippingAddressCity' => $p['cidade'],
            'shippingAddressState' => $p['uf'], 'shippingAddressCountry' => 'BRA',
            'excludePaymentMethodGroup' => 'BOLETO,DEPOSIT', 'excludePaymentMethodName' => 'BOLETO',
        ]);
        $code = $this->xml($xml)?->code ?? null;
        if (!$code) {
            throw new GatewayException('PagSeguro recusou o checkout.');
        }
        return new Emissao(self::SEGUNDA_VIA . $code, tokenCartao: (string) $code, numDocumento: (string) $t->id);
    }

    public function consultar(Titulo $t, ContaGateway $c): Situacao
    {
        $xml = $this->http->form(rtrim($this->proxyUrl, '/') . '/pagseguro/transactions.php',
            ['Debug' => 'false', 'PagSeguroEmail' => (string) $c->pagseguroEmail, 'Token' => $c->token, 'reference' => (string) $t->id]);
        $doc = $this->xml($xml);
        foreach ($doc?->transactions?->transaction ?? [] as $tr) {
            $status = (string) $tr->status;
            $cod = (string) $tr->code;
            if (in_array($status, ['3', '4'], true)) {
                return new Situacao(Situacao::PAGO, substr((string) $tr->lastEventDate, 0, 10) ?: date('Y-m-d'), "pagseguro:{$cod}");
            }
            if (in_array($status, ['6', '7'], true)) {
                return new Situacao(Situacao::CANCELADO, null, "pagseguro:{$cod}");
            }
        }
        return new Situacao(Situacao::PENDENTE);
    }

    public function urlSegundaVia(Titulo $t): ?string
    {
        return $t->tokenCartao ? self::SEGUNDA_VIA . $t->tokenCartao : null;
    }

    private function xml(string $s): ?\SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        // LIBXML_NONET: nunca busca entidades externas (proteção contra XXE)
        $x = simplexml_load_string($s, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_use_internal_errors($prev);
        return $x === false ? null : $x;
    }
}
