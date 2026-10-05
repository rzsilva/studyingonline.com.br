<?php

declare(strict_types=1);

/**
 * Executa migrations/*.sql ainda não aplicadas (controle na tabela SCHEMA_MIGRATION).
 * Uso: php bin/migrate.php [--status]
 * Na Locaweb sem acesso a CLI, rode localmente apontando o .env para o banco remoto.
 */

use App\Support\Database;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';
Dotenv::createImmutable(dirname(__DIR__))->load();
$settings = require dirname(__DIR__) . '/config/settings.php';

$db = Database::connect($settings['db']);
$db->exec('CREATE TABLE IF NOT EXISTS SCHEMA_MIGRATION (
    ARQUIVO VARCHAR(190) PRIMARY KEY, APLICADO_EM DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$applied = $db->query('SELECT ARQUIVO FROM SCHEMA_MIGRATION')->fetchAll(PDO::FETCH_COLUMN);
$files = glob(dirname(__DIR__) . '/migrations/*.sql');
sort($files);

$statusOnly = in_array('--status', $argv, true);
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        echo "  ok   {$name}\n";
        continue;
    }
    if ($statusOnly) {
        echo "  pend {$name}\n";
        continue;
    }
    echo "  run  {$name} ... ";
    // DDL no MySQL faz commit implícito; cada comando é executado separadamente.
    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        $db->exec($stmt);
    }
    $db->prepare('INSERT INTO SCHEMA_MIGRATION (ARQUIVO, APLICADO_EM) VALUES (?, NOW())')->execute([$name]);
    echo "feito\n";
}
