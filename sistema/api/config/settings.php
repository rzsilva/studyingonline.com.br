<?php

declare(strict_types=1);

$env = static fn (string $key, mixed $default = null): mixed => $_ENV[$key] ?? $default;
$bool = static fn (string $key, bool $default = false): bool =>
    filter_var($env($key, $default ? 'true' : 'false'), FILTER_VALIDATE_BOOLEAN);

return [
    'env'   => $env('APP_ENV', 'production'),
    'debug' => $bool('APP_DEBUG'),
    'db' => [
        'host'   => $env('DB_HOST', '127.0.0.1'),
        'port'   => (int) $env('DB_PORT', 3306),
        'name'   => $env('DB_NAME', ''),
        'user'   => $env('DB_USER', ''),
        'pass'   => $env('DB_PASS', ''),
        'ssl_ca' => $env('DB_SSL_CA') ?: null,
    ],
    'auth' => [
        'jwt_secret'             => (string) $env('JWT_SECRET', ''),
        'jwt_ttl'                => (int) $env('JWT_TTL', 900),
        'refresh_ttl'            => (int) $env('REFRESH_TTL', 1209600),
        'cookie_secure'          => $bool('COOKIE_SECURE', true),
        'legacy_clear_plaintext' => $bool('LEGACY_CLEAR_PLAINTEXT'),
    ],
    'cors_origins' => array_filter(array_map('trim', explode(',', (string) $env('CORS_ORIGINS', '')))),
    'base_domain'  => $env('BASE_DOMAIN', 'studyingonline.com.br'),
    'mail' => [
        'brevo_key' => $env('BREVO_API_KEY', ''),
        'from'      => $env('MAIL_FROM', ''),
        'from_name' => $env('MAIL_FROM_NAME', 'Studying Online'),
    ],
    'app_url'      => rtrim((string) $env('APP_URL', ''), '/'),
    'pagamentos' => [
        'boletocloud_proxy' => (string) $env('BOLETOCLOUD_PROXY_URL', ''),
        'boletocloud_token' => (string) $env('BOLETOCLOUD_API_TOKEN', ''),
        'gateway_proxy'     => (string) $env('GATEWAY_PROXY_URL', ''),
        'mercadopago_api'   => (string) $env('MERCADOPAGO_API_URL', 'https://api.mercadopago.com'),
        'api_publica'       => rtrim((string) ($env('PUBLIC_API_URL') ?: rtrim((string) $env('APP_URL', ''), '/') . '/api'), '/'),
    ],
    'rotinas_token' => (string) $env('ROTINAS_TOKEN', ''),
    'storage_path' => dirname(__DIR__) . '/storage',
];
