<?php

declare(strict_types=1);

namespace App\Support\Crud;

use App\Domain\Auth\AuthUser;
use App\Support\ApiException;
use App\Support\Connection;

/**
 * CRUD genérico com isolamento por instituição:
 * - toda leitura/escrita filtra por t.INSTITUICAO_ID do usuário autenticado
 * - só colunas declaradas em Resource::$fields são gravadas (sem mass assignment)
 * - FKs são verificadas na mesma instituição (impede referenciar dados de outra escola)
 */
final class CrudRepository
{
    private const MAX_PAGE = 200;

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array{0: array, 1: array} [linhas, meta] */
    public function list(Resource $r, AuthUser $user, array $query): array
    {
        [$where, $params] = $this->scope($r, $user);

        foreach ($r->filters as $key => $expr) {
            if (isset($query[$key]) && $query[$key] !== '') {
                $where[] = "{$expr} = ?";
                $params[] = $query[$key];
            }
        }
        $q = trim((string) ($query['q'] ?? ''));
        if ($q !== '' && $r->search) {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(' . implode(' OR ', array_map(static fn ($c) => "{$c} LIKE ?", $r->search)) . ')';
            array_push($params, ...array_fill(0, count($r->search), $like));
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(self::MAX_PAGE, max(1, (int) ($query['perPage'] ?? 50)));
        $whereSql = implode(' AND ', $where);
        $from = "FROM {$r->table} t " . implode(' ', $r->joins) . " WHERE {$whereSql}";

        $total = (int) $this->db->run("SELECT COUNT(*) {$from}", $params)->fetchColumn();
        $rows = $this->db->run(
            'SELECT ' . $this->selectList($r) . " {$from} ORDER BY {$r->orderBy} LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage),
            $params
        )->fetchAll();

        return [
            Naming::rows($rows, $r->boolColumns()),
            ['page' => $page, 'perPage' => $perPage, 'total' => $total],
        ];
    }

    public function find(Resource $r, AuthUser $user, int $id): array
    {
        [$where, $params] = $this->scope($r, $user);
        $where[] = 't.ID = ?';
        $params[] = $id;
        $row = $this->db->run(
            'SELECT ' . $this->selectList($r) . " FROM {$r->table} t " . implode(' ', $r->joins) . ' WHERE ' . implode(' AND ', $where),
            $params
        )->fetch();
        if (!$row) {
            throw ApiException::notFound();
        }
        return Naming::row($row, $r->boolColumns());
    }

    public function create(Resource $r, AuthUser $user, array $input): array
    {
        $data = $this->validate($r, $user, $input, true);
        $data['INSTITUICAO_ID'] = $user->instituicaoId;
        if ($r->ownedBy($user)) {
            $data[$r->ownerColumn] = $user->id;
        }
        $this->assertUnique($r, $user, $data, null);
        if ($r->beforeWrite) {
            $data = ($r->beforeWrite)($data, $user, null);
        }
        $data += $r->createDefaults;
        $cols = array_keys($data);
        $sql = "INSERT INTO {$r->table} (" . implode(', ', $cols)
            . ($r->createdAt ? ", {$r->createdAt}" : '')
            . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?'))
            . ($r->createdAt ? ', NOW()' : '') . ')';
        $this->write(fn () => $this->db->run($sql, array_values($data)));
        return $this->find($r, $user, $this->db->lastInsertId());
    }

    public function update(Resource $r, AuthUser $user, int $id, array $input): array
    {
        $this->find($r, $user, $id); // 404 se não for da instituição/do aluno
        $data = $this->validate($r, $user, $input, false);
        if ($r->ownedBy($user)) {
            unset($data[$r->ownerColumn]);
        }
        $this->assertUnique($r, $user, $data, $id);
        if ($r->beforeWrite) {
            $data = ($r->beforeWrite)($data, $user, $id);
        }
        if ($data) {
            $set = implode(', ', array_map(static fn ($c) => "{$c} = ?", array_keys($data)));
            $this->write(fn () => $this->db->run(
                "UPDATE {$r->table} SET {$set} WHERE ID = ? AND INSTITUICAO_ID = ?",
                [...array_values($data), $id, $user->instituicaoId]
            ));
        }
        return $this->find($r, $user, $id);
    }

    public function delete(Resource $r, AuthUser $user, int $id): void
    {
        $this->find($r, $user, $id);
        try {
            $this->db->run("DELETE FROM {$r->table} WHERE ID = ? AND INSTITUICAO_ID = ?", [$id, $user->instituicaoId]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new ApiException("{$r->label} em uso por outros registros; não pode ser excluído(a).", 409, 'conflict');
            }
            throw $e;
        }
    }

    /** Valores únicos dentro da instituição (ex.: e-mail de usuário). */
    private function assertUnique(Resource $r, AuthUser $user, array $data, ?int $id): void
    {
        $errors = [];
        foreach ($r->unique as $col) {
            if (!isset($data[$col]) || $data[$col] === '') {
                continue;
            }
            $dup = $this->db->run(
                "SELECT 1 FROM {$r->table} WHERE {$col} = ? AND INSTITUICAO_ID = ?" . ($id ? ' AND ID <> ?' : '') . ' LIMIT 1',
                $id ? [$data[$col], $user->instituicaoId, $id] : [$data[$col], $user->instituicaoId]
            )->fetchColumn();
            if ($dup) {
                $errors[Naming::toCamel($col)] = 'Já cadastrado na instituição.';
            }
        }
        if ($errors) {
            throw ApiException::validation($errors);
        }
    }

    /** Converte "coluna NOT NULL" do MySQL (1048) em erro de validação no campo. */
    private function write(callable $fn): void
    {
        try {
            $fn();
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1048 && preg_match("/Column '([A-Z0-9_]+)'/", $e->getMessage(), $m)) {
                throw ApiException::validation([Naming::toCamel($m[1]) => 'Campo obrigatório.']);
            }
            throw $e;
        }
    }

    /** @return array{0: string[], 1: array} */
    private function scope(Resource $r, AuthUser $user): array
    {
        $where = ['t.INSTITUICAO_ID = ?'];
        $params = [$user->instituicaoId];
        if ($r->ownedBy($user)) {
            $where[] = "t.{$r->ownerColumn} = ?";
            $params[] = $user->id;
        }
        return [$where, $params];
    }

    private function selectList(Resource $r): string
    {
        $cols = ['t.ID'];
        foreach ($r->fields as $f) {
            $cols[] = "t.{$f->column}";
        }
        if ($r->createdAt) {
            $cols[] = "t.{$r->createdAt}";
        }
        return implode(', ', array_unique([...$cols, ...$r->extraSelect]));
    }

    /** @return array<string,mixed> COLUNA => valor já convertido */
    public function validate(Resource $r, AuthUser $user, array $input, bool $creating): array
    {
        $data = [];
        $errors = [];
        foreach ($r->fields as $f) {
            $key = $f->key();
            $present = array_key_exists($key, $input)
                && !($user->isAluno() && in_array($f->column, $r->staffOnlyColumns, true));
            if (!$present) {
                if ($creating) {
                    if ($f->required && $f->default === null && !($r->ownerColumn === $f->column && $r->ownedBy($user))) {
                        $errors[$key] = 'Campo obrigatório.';
                    } elseif ($f->default !== null) {
                        $data[$f->column] = $f->default;
                    }
                }
                continue;
            }
            try {
                $value = $this->cast($f, $input[$key]);
            } catch (\InvalidArgumentException $e) {
                $errors[$key] = $e->getMessage();
                continue;
            }
            if ($value === null && $f->default !== null) {
                $value = $f->default; // campo limpo no formulário volta ao padrão (colunas NOT NULL)
            }
            if ($value === null && $f->required) {
                $errors[$key] = 'Campo obrigatório.';
                continue;
            }
            if ($value !== null && $f->type === 'ref' && !$this->refExists($f, (int) $value, $user)) {
                $errors[$key] = 'Registro relacionado não encontrado.';
                continue;
            }
            $data[$f->column] = $value;
        }
        if ($errors) {
            throw ApiException::validation($errors);
        }
        return $data;
    }

    private function cast(Field $f, mixed $v): mixed
    {
        if ($v === null || (is_string($v) && trim($v) === '' && $f->type !== 'bool')) {
            return null;
        }
        switch ($f->type) {
            case 'string':
                if (!is_scalar($v)) {
                    throw new \InvalidArgumentException('Texto inválido.');
                }
                $v = trim((string) $v);
                if ($f->max !== null && mb_strlen($v) > $f->max) {
                    throw new \InvalidArgumentException("Máximo de {$f->max} caracteres.");
                }
                return $v;
            case 'url':
                $v = trim((string) $v);
                if (!preg_match('#^https?://#i', $v) || filter_var($v, FILTER_VALIDATE_URL) === false) {
                    throw new \InvalidArgumentException('Informe uma URL iniciando com http:// ou https://.');
                }
                return $v;
            case 'email':
                $v = mb_strtolower(trim((string) $v));
                if (filter_var($v, FILTER_VALIDATE_EMAIL) === false || mb_strlen($v) > (int) $f->max) {
                    throw new \InvalidArgumentException('E-mail inválido.');
                }
                return $v;
            case 'cpf':
                $d = preg_replace('/\D/', '', (string) $v);
                if (!Cpf::valido($d)) {
                    throw new \InvalidArgumentException('CPF inválido.');
                }
                return Cpf::formatar($d); // legado grava com máscara 999.999.999-99
            case 'int':
            case 'ref':
                if (filter_var($v, FILTER_VALIDATE_INT) === false) {
                    throw new \InvalidArgumentException('Número inteiro inválido.');
                }
                return (int) $v;
            case 'decimal':
                $n = is_string($v) ? str_replace(',', '.', $v) : $v;
                if (!is_numeric($n)) {
                    throw new \InvalidArgumentException('Número inválido.');
                }
                return (string) round((float) $n, 2);
            case 'bool':
                return filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            case 'date':
                $d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) $v, 0, 10));
                if (!$d) {
                    throw new \InvalidArgumentException('Data inválida (use AAAA-MM-DD).');
                }
                return $d->format('Y-m-d');
            case 'time':
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string) $v)) {
                    throw new \InvalidArgumentException('Hora inválida (use HH:MM).');
                }
                return strlen((string) $v) === 5 ? "{$v}:00" : (string) $v;
            case 'enum':
                if (!in_array((string) $v, $f->enum, true)) {
                    throw new \InvalidArgumentException('Valor inválido.');
                }
                return (string) $v;
        }
        throw new \LogicException("Tipo desconhecido: {$f->type}");
    }

    private function refExists(Field $f, int $id, AuthUser $user): bool
    {
        $sql = "SELECT 1 FROM {$f->refTable} WHERE ID = ?" . ($f->refTenant ? ' AND INSTITUICAO_ID = ?' : '');
        $params = $f->refTenant ? [$id, $user->instituicaoId] : [$id];
        return (bool) $this->db->run($sql, $params)->fetchColumn();
    }
}
