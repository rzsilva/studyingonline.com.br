<?php

declare(strict_types=1);

/**
 * Simulador dos provedores de pagamento para testes locais (NUNCA usar em produção).
 * Rodar: php -S 127.0.0.1:8098 tests/mock/gateways.php
 *   /boletocloud/Criar|Retorno       — proxy BoletoCloud da Adaline (JSON)
 *   /gw/customers.php ... bills.php  — proxy Vindi da Adaline (form)
 *   /gw/pagseguro/checkout.php|transactions.php — proxy PagSeguro (form → XML)
 *   /mp/checkout/preferences, /mp/v1/payments/{id}, /mp/v1/payments/search — API MercadoPago
 *   POST /_mock/pagar {gateway, ref, status?}  — marca um pagamento (controle do teste)
 *   POST /_mock/falhar {on: bool}             — faz todos os provedores responderem 500
 *   GET  /_mock/estado                        — estado atual (inspeção)
 */

$estadoArq = sys_get_temp_dir() . '/so-mock-gateways.json';
$estado = is_file($estadoArq) ? json_decode((string) file_get_contents($estadoArq), true) : [];
$estado += ['pagos' => [], 'falhar' => false, 'chamadas' => [], 'seq' => 1000, 'prefs' => []];
$salvar = static function () use (&$estado, $estadoArq) {
    file_put_contents($estadoArq, json_encode($estado));
};
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raw = (string) file_get_contents('php://input');
$json = json_decode($raw, true) ?: [];
$form = $_POST;
$responder = static function ($dados, int $status = 200, string $tipo = 'application/json') {
    http_response_code($status);
    header("Content-Type: {$tipo}");
    echo is_string($dados) ? $dados : json_encode($dados);
};

if (str_starts_with($uri, '/_mock/')) {
    if ($uri === '/_mock/pagar') {
        $estado['pagos'][$json['gateway'] . ':' . $json['ref']] = $json['status'] ?? 'pago';
    } elseif ($uri === '/_mock/falhar') {
        $estado['falhar'] = (bool) ($json['on'] ?? false);
    } elseif ($uri === '/_mock/reset') {
        $estado = ['pagos' => [], 'falhar' => false, 'chamadas' => [], 'seq' => 1000, 'prefs' => []];
    }
    $salvar();
    $responder($estado);
    return;
}

$estado['chamadas'][] = $uri;
if ($estado['falhar']) {
    $salvar();
    $responder(['erro' => 'indisponível'], 500);
    return;
}

switch (true) {
    /* ---------------- BoletoCloud (proxy) ---------------- */
    case $uri === '/boletocloud/Criar':
        if (($json['BoletoApiToken'] ?? '') === '' || ($json['BoletoContaToken'] ?? '') === '') {
            $responder(['Status' => false, 'Message' => 'token ausente']);
            break;
        }
        $responder(['Status' => true, 'BoletoToken' => 'bc-' . $json['BoletoNumDocumento']]);
        break;
    case $uri === '/boletocloud/Retorno':
        $lista = [];
        foreach ($estado['pagos'] as $k => $st) {
            if (str_starts_with($k, 'boletocloud:') && $st === 'pago') {
                $lista[] = ['token' => substr($k, 12), 'ocorrencias' => [['situacao' => 'LIQUIDACAO', 'info' => ['dataDePagamento' => date('Y-m-d', strtotime('-1 day'))]]]];
            }
        }
        $responder($lista);
        break;

    /* ---------------- Vindi (proxy) ---------------- */
    case $uri === '/gw/customers.php':
        $responder(['customers' => []]);
        break;
    case $uri === '/gw/customers_create.php':
        $responder(['customer' => ['id' => 77, 'code' => $form['code'] ?? '']]);
        break;
    case $uri === '/gw/bills_create.php':
        $id = ++$estado['seq'];
        $responder(['bill' => ['id' => $id, 'status' => 'pending', 'url' => "https://app.vindi.com.br/customer/bills/{$id}?token=tk{$id}"]]);
        break;
    case $uri === '/gw/bills.php':
        $st = $estado['pagos']['vindi:' . ($form['id'] ?? '')] ?? 'pendente';
        $responder(['bill' => ['id' => (int) ($form['id'] ?? 0), 'status' => $st === 'pago' ? 'paid' : ($st === 'cancelado' ? 'canceled' : 'pending'),
            'charges' => [['paid_at' => date('Y-m-d\TH:i:s')]]]]);
        break;

    /* ---------------- PagSeguro (proxy, XML) ---------------- */
    case $uri === '/gw/pagseguro/checkout.php':
        $responder('<?xml version="1.0"?><checkout><code>PS' . ($form['reference'] ?? '0') . 'CODE</code><date>' . date('c') . '</date></checkout>', 200, 'application/xml');
        break;
    case $uri === '/gw/pagseguro/transactions.php':
        $st = $estado['pagos']['pagseguro:' . ($form['reference'] ?? '')] ?? null;
        $tr = $st ? '<transaction><code>T' . $form['reference'] . '</code><reference>' . $form['reference'] . '</reference><status>'
            . ($st === 'pago' ? '3' : '7') . '</status><lastEventDate>' . date('Y-m-d\TH:i:s') . '</lastEventDate></transaction>' : '';
        $responder('<?xml version="1.0"?><transactionSearchResult><transactions>' . $tr . '</transactions></transactionSearchResult>', 200, 'application/xml');
        break;

    /* ---------------- MercadoPago (API) ---------------- */
    case str_starts_with($uri, '/mp/'):
        if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer TESTE-MP') {
            $responder(['message' => 'unauthorized'], 401);
            break;
        }
        if ($uri === '/mp/checkout/preferences') {
            $id = 'pref-' . (++$estado['seq']);
            $estado['prefs'][$id] = ['ref' => $json['external_reference'] ?? '', 'notification_url' => $json['notification_url'] ?? '', 'valor' => $json['items'][0]['unit_price'] ?? 0];
            $responder(['id' => $id, 'init_point' => "https://www.mercadopago.com.br/checkout/v1/redirect?pref_id={$id}"], 201);
        } elseif (preg_match('#^/mp/v1/payments/(\d+)$#', $uri, $m)) {
            // pagamento simulado: id = número do título; status vem de _mock/pagar com gateway "mercadopago"
            $st = $estado['pagos']['mercadopago:' . $m[1]] ?? 'pendente';
            $responder(['id' => (int) $m[1], 'external_reference' => 'so-cr-' . $m[1],
                'status' => $st === 'pago' ? 'approved' : ($st === 'cancelado' ? 'cancelled' : 'pending'), 'date_approved' => date('c')]);
        } elseif ($uri === '/mp/v1/payments/search') {
            parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $q);
            $tid = (int) preg_replace('/\D/', '', (string) ($q['external_reference'] ?? ''));
            $st = $estado['pagos']['mercadopago:' . $tid] ?? null;
            $responder(['results' => $st ? [['id' => $tid, 'external_reference' => "so-cr-{$tid}",
                'status' => $st === 'pago' ? 'approved' : 'cancelled', 'date_approved' => date('c')]] : []]);
        } else {
            $responder(['message' => 'not found'], 404);
        }
        break;

    default:
        $responder(['erro' => 'rota desconhecida no simulador', 'uri' => $uri], 404);
}
$salvar();
