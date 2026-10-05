<?php

declare(strict_types=1);

/*
 * Ponte pública da API. O código (src, vendor, .env) fica FORA da pasta pública:
 *  - padrão: pasta "api" ao lado de public_html  (../../api)
 *  - se a hospedagem não permitir, publique em public_html/_app (protegida por web.config)
 */
$candidates = [dirname(__DIR__, 2) . '/api', dirname(__DIR__) . '/_app'];
foreach ($candidates as $root) {
    if (is_file($root . '/bootstrap/app.php')) {
        (require $root . '/bootstrap/app.php')->run();
        return;
    }
}
http_response_code(500);
header('Content-Type: application/json');
echo '{"error":{"code":"misconfigured","message":"API não instalada."}}';
