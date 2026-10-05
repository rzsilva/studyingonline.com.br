<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

final class Database
{
    public static function connect(array $cfg): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['port'], $cfg['name']);
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];
        if (!empty($cfg['ssl_ca'])) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $cfg['ssl_ca'];
        }
        return new PDO($dsn, $cfg['user'], $cfg['pass'], $options);
    }
}
