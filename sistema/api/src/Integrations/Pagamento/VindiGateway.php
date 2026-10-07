<?php

declare(strict_types=1);

namespace App\Integrations\Pagamento;

final class VindiGateway implements Gateway
{
    private const SEGUNDA_VIA = 'https://app.vindi.com.br/customer/bills/';

    public function __construct(private readonly HttpCliente $http, private readonly string $proxyUrl, private readonly string $produtoId = '521296')
    {
    }

    public function nome(): string
    {
        return 'Vindi';
    }

    private function base(ContaGateway $c): array
    {
        return ['Debug' => 'false', 'Token' => base64_encode($c->token . ':')];
    }

    private function post(string $acao, array $campos): array
    {
        $d = json_decode($this->http->form(rtrim($this->proxyUrl, '/') . "/{$acao}", $campos), true);
        if (!is_array($d)) {
            throw new GatewayException('Resposta inválida da Vindi.');
        }
        return $d;
    }

    public function emitir(Titulo $t, ContaGateway $c): Emissao
    {
        $cliente = ['code' => (string) ($t->pagador['codigo'] ?? $t->pagador['email']), 'name' => $t->pagador['nome'], 'email' => $t->pagador['email']];
        $r = $this->post('customers.php', $this->base($c) + $cliente);
        $customerId = $r['customers'][0]['id'] ?? $r['customer']['id'] ?? null;
        if (!$customerId) {
            $r = $this->post('customers_create.php', $this->base($c) + $cliente);
            $customerId = $r['customer']['id'] ?? null;
        }
        if (!$customerId) {
            throw new GatewayException('Vindi: não foi possível criar o cliente.');
        }
        $r = $this->post('bills_create.php', $this->base($c) + [
            'customer_id' => (string) $customerId, 'payment_method_code' => 'credit_card', 'due_at' => $t->vencimento,
            'product_id' => $this->produtoId, 'description' => $t->descricao, 'amount' => number_format($t->valor, 2, '.', ''),
        ]);
        $bill = $r['bill'] ?? null;
        if (empty($bill['id']) || empty($bill['url'])) {
            throw new GatewayException('Vindi recusou a fatura.');
        }
        parse_str((string) parse_url($bill['url'], PHP_URL_QUERY), $q);
        return new Emissao($bill['url'], tokenCartao: $q['token'] ?? null, numDocumento: (string) $bill['id']);
    }

    public function consultar(Titulo $t, ContaGateway $c): Situacao
    {
        $r = $this->post('bills.php', $this->base($c) + ['id' => (string) $t->numDocumento]);
        $bill = $r['bill'] ?? [];
        return match ($bill['status'] ?? '') {
            'paid' => new Situacao(Situacao::PAGO, substr((string) ($bill['charges'][0]['paid_at'] ?? date('Y-m-d')), 0, 10), "vindi:{$bill['id']}"),
            'canceled' => new Situacao(Situacao::CANCELADO, null, "vindi:{$bill['id']}"),
            default => new Situacao(Situacao::PENDENTE),
        };
    }

    public function urlSegundaVia(Titulo $t): ?string
    {
        return $t->numDocumento && $t->tokenCartao ? self::SEGUNDA_VIA . $t->numDocumento . '?token=' . $t->tokenCartao : null;
    }
}
