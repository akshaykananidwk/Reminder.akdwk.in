<?php

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper. Every query goes through prepared statements — there is no
 * API on this class that accepts interpolated values.
 */
class Database
{
    private ?PDO $pdo = null;

    public function __construct(
        private string $host,
        private int $port,
        private string $name,
        private string $user,
        private string $pass,
        private string $charset = 'utf8mb4'
    ) {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $this->host, $this->port, $this->name, $this->charset);

            $this->pdo = new PDO($dsn, $this->user, $this->pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);

            $this->pdo->exec("SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
            $this->pdo->exec("SET time_zone = '+05:30'");
        }

        return $this->pdo;
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);

        foreach ($params as $key => $value) {
            $param = is_int($key) ? $key + 1 : (str_starts_with((string) $key, ':') ? $key : ':' . $key);

            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                is_null($value) => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };

            $stmt->bindValue($param, $value, $type);
        }

        $stmt->execute();

        return $stmt;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    public function value(string $sql, array $params = [], mixed $default = null): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();

        return $value === false ? $default : $value;
    }

    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn ($c) => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $this->safeIdentifier($table),
            '`' . implode('`, `', $columns) . '`',
            implode(', ', $placeholders)
        );

        $this->query($sql, $data);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * INSERT ... ON DUPLICATE KEY UPDATE for the given update columns.
     */
    public function upsert(string $table, array $data, array $updateColumns): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn ($c) => ':' . $c, $columns);

        $updates = [];
        foreach ($updateColumns as $col) {
            $updates[] = sprintf('`%s` = VALUES(`%s`)', $col, $col);
        }

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
            $this->safeIdentifier($table),
            '`' . implode('`, `', $columns) . '`',
            implode(', ', $placeholders),
            implode(', ', $updates)
        );

        $this->query($sql, $data);

        return (int) $this->pdo()->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        $params = [];

        foreach ($data as $column => $value) {
            $sets[] = sprintf('`%s` = :set_%s', $column, $column);
            $params['set_' . $column] = $value;
        }

        foreach ($whereParams as $key => $value) {
            $params[ltrim((string) $key, ':')] = $value;
        }

        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $this->safeIdentifier($table), implode(', ', $sets), $where);

        return $this->query($sql, $params)->rowCount();
    }

    public function delete(string $table, string $where, array $params = []): int
    {
        $sql = sprintf('DELETE FROM `%s` WHERE %s', $this->safeIdentifier($table), $where);

        return $this->query($sql, $params)->rowCount();
    }

    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();

        if ($pdo->inTransaction()) {
            return $callback($this);
        }

        $pdo->beginTransaction();

        try {
            $result = $callback($this);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function tableExists(string $table): bool
    {
        $row = $this->one(
            'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );

        return (int) ($row['c'] ?? 0) > 0;
    }

    public function databaseName(): string
    {
        return $this->name;
    }

    /**
     * Table names are never user supplied, but guard anyway.
     */
    private function safeIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new \InvalidArgumentException('Unsafe SQL identifier: ' . $identifier);
        }

        return $identifier;
    }
}
