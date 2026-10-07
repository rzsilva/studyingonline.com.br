<?php

declare(strict_types=1);

namespace App\Integrations\Pagamento;

final class MercadoPagoGateway implements Gateway
{
    public function __construct(
        private readonly HttpCliente $http,
        private readonly string $apiUrl,
        private readonly string $webhookBase, // ex.: https://dominio/api/webhooks/mercadopago
    ) {
    }

    public function nome(): string
    {
        return 'MercadoPago';
    }

    public function webhookUrl(int $instituicaoId, string $token): string
    {
        return rtrim($this->webhookBase, '/') . "/{$instituicaoId}/{$token}";
    }

    public static function referencia(int $tituloId): string
    {
        return "so-cr-{$tituloId}";
    }

    private function auth(ContaGateway $c): array
    {
        return ["Authorization: Bearer {$c->token}"];
    }

    public function emitir(Titulo $t, ContaGateway $c, string $webhookToken = ''): Emissao
    {
        $r = $this->http->json('POST', rtrim($this->apiUrl, '/') . '/checkout/preferences', [
            'items' => [['id' => (string) $t->id, 'title' => $t->descricao, 'quantity' => 1, 'currency_id' => 'BRL', 'unit_price' => $t->valor]],
            'payer' => ['name' => $t->pagador['nome'], 'email' => $t->pagador['email'],
                'identification' => ['type' => 'CPF', 'number' => preg_replace('/\D/', '', $t->pagador['cpf'])]],
            'external_reference' => self::referencia($t->id),
            'notification_url' => $this->webhookUrl($t->instituicaoId, $webhookToken),
            'date_of_expiration' => date('c', strtotime($t->vencimento . ' 23:59:59 +5 days')),
        ], $this->auth($c));
        if (empty($r['id']) || empty($r['init_point'])) {
            throw new GatewayException('MercadoPago não criou o link de pagamento.');
        }
        return new Emissao($r['init_point'], tokenCartao: (string) $r['id'], numDocumento: self::referencia($t->id));
    }

    public function consultar(Titulo $t, ContaGateway $c): Situacao
    {
        $r = $this->http->json('GET', rtrim($this->apiUrl, '/') . '/v1/payments/search?external_reference=' . urlencode(self::referencia($t->id)), null, $this->auth($c));
        $estado = new Situacao(Situacao::PENDENTE);
        foreach ($r['results'] ?? [] as $p) {
            $s = $this->situacaoPagamento($p);
            if ($s->status === Situacao::PAGO) {
                return $s;
            }
            if ($s->status === Situacao::CANCELADO) {
                $estado = $s;
            }
        }
        return $estado;
    }

    /** Busca o pagamento na API (fonte da verdade do webhook — nunca confia no corpo da notificação). */
    public function pagamento(ContaGateway $c, string $paymentId): array
    {
        if (!preg_match('/^\d+$/', $paymentId)) {
            throw new GatewayException('Id de pagamento inválido.');
        }
        return $this->http->json('GET', rtrim($this->apiUrl, '/') . "/v1/payments/{$paymentId}", null, $this->auth($c));
    }

    public function situacaoPagamento(array $p): Situacao
    {
        $id = 'mercadopago:' . ($p['id'] ?? '?');
        return match ($p['status'] ?? '') {
            'approved' => new Situacao(Situacao::PAGO, substr((string) ($p['date_approved'] ?? date('Y-m-d')), 0, 10), $id),
            'cancelled', 'refunded', 'charged_back' => new Situacao(Situacao::CANCELADO, null, $id),
            default => new Situacao(Situacao::PENDENTE, null, $id),
        };
    }

    public function urlSegundaVia(Titulo $t): ?string
    {
        return null; // o link do Checkout Pro é regenerado em emitir() quando necessário
    }
}
