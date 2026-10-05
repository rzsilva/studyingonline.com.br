<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOStatement;

/**
 * Conexão aberta só na primeira consulta: rotas que falham antes (validação,
 * auth, 404) não gastam conexão no MySQL compartilhado.
 */
final class Connection
{
    private ?PDO $pdo = null;

    public function __construct(private readonly array $config)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= Database::connect($this->config);
    }

    public function prepare(string $sql): PDOStatement
    {
        return $this->pdo()->prepare($sql);
    }

    public function run(string $sql, array $params = []): PDOStatement
    {
        $st = $this->pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public function beginTransaction(): void
    {
        $this->pdo()->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo()->commit();
    }

    public function rollBack(): void
    {
        if ($this->pdo()->inTransaction()) {
            $this->pdo()->rollBack();
        }
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo()->lastInsertId();
    }
}
