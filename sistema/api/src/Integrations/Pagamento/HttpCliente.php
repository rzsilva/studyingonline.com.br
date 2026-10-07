<?php

declare(strict_types=1);

namespace App\Integrations\Pagamento;

/** Cliente HTTP mínimo (cURL) com timeout; erros de rede/HTTP viram GatewayException. */
final class HttpCliente
{
    public function __construct(private readonly int $timeout = 20)
    {
    }

    /** @return array{status:int, body:string} */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erro = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            throw new GatewayException("Falha de comunicação com o provedor de pagamento: {$erro}");
        }
        return ['status' => $status, 'body' => (string) $resp];
    }

    public function json(string $method, string $url, ?array $payload = null, array $headers = []): array
    {
        $r = $this->request($method, $url, array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
            $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            throw new GatewayException("Provedor respondeu HTTP {$r['status']}: " . mb_substr($r['body'], 0, 300));
        }
        $d = json_decode($r['body'], true);
        if (!is_array($d)) {
            throw new GatewayException('Resposta inválida do provedor de pagamento.');
        }
        return $d;
    }

    /** POST application/x-www-form-urlencoded (formato dos proxies da Adaline). */
    public function form(string $url, array $campos): string
    {
        $r = $this->request('POST', $url, ['Content-Type: application/x-www-form-urlencoded'], http_build_query($campos));
        if ($r['status'] !== 200) {
            throw new GatewayException("Provedor respondeu HTTP {$r['status']}.");
        }
        return $r['body'];
    }
}
