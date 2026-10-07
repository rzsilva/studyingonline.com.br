<?php
// TEMPORÁRIO: diagnóstico do sistema em produção. APAGAR do servidor após o uso.
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

echo 'PHP ', PHP_VERSION, "\n";
foreach (['pdo_mysql', 'mbstring', 'openssl', 'curl', 'sodium', 'json', 'fileinfo'] as $x) {
    echo "$x: ", extension_loaded($x) ? 'ok' : 'FALTA', "\n";
}
foreach (['REQUEST_URI', 'HTTP_X_ORIGINAL_URL', 'HTTP_X_REWRITE_URL', 'SCRIPT_NAME', 'HTTP_HOST', 'HTTPS'] as $k) {
    echo "$k = ", $_SERVER[$k] ?? '-', "\n";
}
$app = __DIR__ . '/sistema/_app';
foreach (['storage/logs', 'storage/cache'] as $d) {
    echo "$d gravável: ", is_writable("$app/$d") ? 'sim' : 'NÃO', "\n";
}
echo "\n";

if (PHP_VERSION_ID < 80100) {
    exit("PHP abaixo de 8.1: a API não roda. Mude a versão no painel da Locaweb.\n");
}
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
        echo "\nFATAL: {$e['message']} em {$e['file']}:{$e['line']}\n";
    }
});
try {
    $slim = require "$app/bootstrap/app.php";
    echo 'bootstrap OK: ', get_class($slim), ' | basePath = ', $slim->getBasePath(), "\n";
} catch (Throwable $t) {
    echo 'ERRO: ', get_class($t), ': ', $t->getMessage(), ' em ', $t->getFile(), ':', $t->getLine(), "\n";
}
