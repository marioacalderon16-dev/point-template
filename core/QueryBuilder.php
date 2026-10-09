<?php
declare(strict_types=1);
namespace Core;

use PDO;

final class QueryBuilder
{
    private const ALLOWED_OPERATORS = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IS', 'IS NOT'];
    private const IDENTIFIER_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/';

    private PDO $pdo;
    private array $query = [];
    private array $bindings = [];
    private string $table = '';
    private bool $softDeleteEnabled = false;
    private bool $withTrashed = false;
    private bool $onlyTrashed = false;

    private static array $scopes = [];

    private static function validateIdentifier(string $identifier): string
    {
        $clean = trim($identifier);
        if (!preg_match(self::IDENTIFIER_PATTERN, $clean)) {
            throw new \InvalidArgumentException("Invalid SQL identifier: {$identifier}");
        }
        return $clean;
    }

    private static function validateIdentifiers(array $identifiers): array
    {
        return array_map([self::class, 'validateIdentifier'], $identifiers);
    }

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->reset();
    }

    public static function connection(
        string $dsn,
        string $user = '',
        string $pass = '',
        array $options = []
    ): PDO {
        $defaultOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Emulación configurable (p.ej. poolers en modo transacción); default false
            PDO::ATTR_EMULATE_PREPARES => filter_var($_ENV['DB_EMULATE_PREPARES'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];

        $tz = $_ENV['TIME_ZONE'] ?? 'UTC';

        if (str_starts_with($dsn, 'mysql:')) {
            $defaultOptions[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET time_zone = '" . addslashes($tz) . "'";
        }

        return new PDO($dsn, $user, $pass, array_replace($defaultOptions, $options));
    }

    public static function table(string $table, PDO $pdo): self
    {
        return (new self($pdo))->from(self::validateIdentifier($table));
    }

    // SELECT

    public function from(string $table): self
    {
        $this->table = self::validateIdentifier($table);
        $this->query['type'] = 'SELECT';
        return $this;
    }

    public function select(string ...$columns): self
    {
        if (empty($columns)) {
            $this->query['select'] = ['*'];
        } else {
            $this->query['select'] = array_map(function (string $col) {
                if (preg_match('/^(.+)\s+as\s+(\w+)$/i', $col, $m)) {
                    return self::validateIdentifier($m[1]) . ' as ' . self::validateIdentifier($m[2]);
                }
                if (preg_match('/^(COUNT|SUM|AVG|MIN|MAX)\((\*|[a-zA-Z_][a-zA-Z0-9_.]*)\)\s*(?:as\s+(\w+))?$/i', $col, $m)) {
                    $fn = strtoupper($m[1]);
                    $arg = $m[2] === '*' ? '*' : self::validateIdentifier($m[2]);
                    $alias = isset($m[3]) && $m[3] !== '' ? ' as ' . self::validateIdentifier($m[3]) : '';
                    return "{$fn}({$arg}){$alias}";
                }
                return self::validateIdentifier($col);
            }, $columns);
        }
        return $this;
    }

    public function where(string $column, mixed $operator = null, mixed $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $column = self::validateIdentifier($column);
        $this->validateOperator($operator);
        $placeholder = $this->addBinding($value);
        $condition = "$column $operator $placeholder";

        $this->query['where'][] = isset($this->query['where']) ? "AND $condition" : $condition;
        return $this;
    }

    public function whereIn(string $column, array $values): self
    {
        $column = self::validateIdentifier($column);
        $placeholders = array_map(fn($val) => $this->addBinding($val), $values);
        $condition = "$column IN (" . implode(',', $placeholders) . ")";

        $this->query['where'][] = isset($this->query['where']) ? "AND $condition" : $condition;
        return $this;
    }

    public function orWhere(string $column, mixed $operator = null, mixed $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $column = self::validateIdentifier($column);
        $this->validateOperator($operator);
        $placeholder = $this->addBinding($value);
        $this->query['where'][] = "OR $column $operator $placeholder";
        return $this;
    }

    public function whereNotIn(string $column, array $values): self
    {
        $column = self::validateIdentifier($column);
        $placeholders = array_map(fn($val) => $this->addBinding($val), $values);
        $condition = "$column NOT IN (" . implode(',', $placeholders) . ")";

        $this->query['where'][] = isset($this->query['where']) ? "AND $condition" : $condition;
        return $this;
    }

    public function whereNull(string $column): self
    {
        $column = self::validateIdentifier($column);
        $condition = "$column IS NULL";
        $this->query['where'][] = isset($this->query['where']) ? "AND $condition" : $condition;
        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $column = self::validateIdentifier($column);
        $condition = "$column IS NOT NULL";
        $this->query['where'][] = isset($this->query['where']) ? "AND $condition" : $condition;
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $column = self::validateIdentifier($column);
        $dir = strtoupper($direction);
        if (!in_array($dir, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException("Invalid sort direction: {$direction}");
        }
        $this->query['order'][] = "$column $dir";
        return $this;
    }

    public function limit(int $count, int $offset = 0): self
    {
        $this->query['limit'] = $count;
        if ($offset > 0) {
            $this->query['offset'] = $offset;
        }
        return $this;
    }

    public function join(string $table, string $first, string $operator, string $second): self
    {
        $table = self::validateIdentifier($table);
        $first = self::validateIdentifier($first);
        $this->validateOperator($operator);
        $second = self::validateIdentifier($second);
        $this->query['joins'][] = "JOIN $table ON $first $operator $second";
        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        $table = self::validateIdentifier($table);
        $first = self::validateIdentifier($first);
        $this->validateOperator($operator);
        $second = self::validateIdentifier($second);
        $this->query['joins'][] = "LEFT JOIN $table ON $first $operator $second";
        return $this;
    }

    public function groupBy(string ...$columns): self
    {
        $this->query['group'] = self::validateIdentifiers($columns);
        return $this;
    }

    public function having(string $column, mixed $operator = null, mixed $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $column = self::validateIdentifier($column);
        $this->validateOperator($operator);
        $placeholder = $this->addBinding($value);
        $condition = "$column $operator $placeholder";

        $this->query['having'][] = isset($this->query['having']) ? "AND $condition" : $condition;
        return $this;
    }

    // TRANSACTIONS

    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollBack(): bool
    {
        return $this->pdo->rollBack();
    }

    public function transaction(callable $callback): mixed
    {
        try {
            $this->beginTransaction();
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Exception $e) {
            $this->rollBack();
            throw $e;
        }
    }

    // INSERT / UPDATE / DELETE

    public function insert(array $data): int
    {
        $columns = self::validateIdentifiers(array_keys($data));
        $values = array_map(fn($v) => is_bool($v) ? (int) $v : $v, array_values($data));
        $placeholders = array_map(fn($val) => $this->addBinding($val), $values);

        $sql = "INSERT INTO {$this->table} (" .
            implode(',', $columns) .
            ") VALUES (" .
            implode(',', $placeholders) . ")";
        $this->execute($sql);
        return (int) $this->pdo->lastInsertId();
    }

    /** INSERT ... RETURNING: devuelve la fila insertada (pgsql, sqlite >= 3.35). */
    public function insertReturning(array $data, string $returning = '*'): ?array
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $supported = $driver === 'pgsql'
            || ($driver === 'sqlite' && version_compare($this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION), '3.35.0', '>='));
        if (!$supported) {
            throw new \RuntimeException("insertReturning() no soportado por el driver '{$driver}' (requiere pgsql o sqlite >= 3.35)");
        }

        if ($returning !== '*') {
            $returning = implode(',', self::validateIdentifiers(array_map('trim', explode(',', $returning))));
        }

        $columns = self::validateIdentifiers(array_keys($data));
        $values = array_map(fn($v) => is_bool($v) ? (int) $v : $v, array_values($data));
        $placeholders = array_map(fn($val) => $this->addBinding($val), $values);

        $sql = "INSERT INTO {$this->table} (" . implode(',', $columns) . ") VALUES (" .
            implode(',', $placeholders) . ") RETURNING {$returning}";
        $row = $this->execute($sql)->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function update(array $data): int
    {
        if (empty($this->query['where'])) {
            throw new \RuntimeException("UPDATE without WHERE is not allowed. Use updateAll() if intentional.");
        }

        $sets = [];
        foreach ($data as $column => $value) {
            $col = self::validateIdentifier($column);
            $placeholder = $this->addBinding($value);
            $sets[] = "$col = $placeholder";
        }

        $sql = "UPDATE {$this->table} SET " . implode(',', $sets);
        $sql .= " WHERE " . implode(' ', $this->query['where']);

        return $this->execute($sql)->rowCount();
    }

    public function updateAll(array $data): int
    {
        $sets = [];
        foreach ($data as $column => $value) {
            $col = self::validateIdentifier($column);
            $placeholder = $this->addBinding($value);
            $sets[] = "$col = $placeholder";
        }

        $sql = "UPDATE {$this->table} SET " . implode(',', $sets);

        if (isset($this->query['where'])) {
            $sql .= " WHERE " . implode(' ', $this->query['where']);
        }

        return $this->execute($sql)->rowCount();
    }

    public function delete(): int
    {
        if (empty($this->query['where'])) {
            throw new \RuntimeException("DELETE without WHERE is not allowed. Use deleteAll() if intentional.");
        }

        $sql = "DELETE FROM {$this->table}";
        $sql .= " WHERE " . implode(' ', $this->query['where']);

        return $this->execute($sql)->rowCount();
    }

    public function deleteAll(): int
    {
        $sql = "DELETE FROM {$this->table}";

        if (isset($this->query['where'])) {
            $sql .= " WHERE " . implode(' ', $this->query['where']);
        }

        return $this->execute($sql)->rowCount();
    }

    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    // EXECUTION

    public function get(): array
    {
        return $this->execute($this->buildSelectQuery())->fetchAll();
    }

    public function first(): ?array
    {
        $this->limit(1);
        $result = $this->get();
        return $result[0] ?? null;
    }

    public function find(mixed $id, string $column = 'id'): ?array
    {
        return $this->where(self::validateIdentifier($column), $id)->first();
    }

    public function findOrFail(mixed $id, string $column = 'id'): array
    {
        $column = self::validateIdentifier($column);
        $result = $this->where($column, $id)->first();
        if ($result === null) {
            throw new \RuntimeException("Record not found with $column = $id");
        }
        return $result;
    }

    public function value(string $column): mixed
    {
        $this->query['select'] = [self::validateIdentifier($column)];
        $result = $this->first();
        return $result[$column] ?? null;
    }

    public function count(): int
    {
        $this->query['select'] = ['COUNT(*) as count'];        
        unset($this->query['order']);
        $result = $this->execute($this->buildSelectQuery())->fetch();
        return (int) $result['count'];
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    public function paginate(int $perPage = 15, int $page = 1): array
    {
        $page = max(1, $page);
        $total = $this->cloneQuery()->count();

        $this->limit($perPage, ($page - 1) * $perPage);
        $data = $this->get();

        return [
            'data' => $data,
            'meta' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    public function pluck(string $column): array
    {
        $column = self::validateIdentifier($column);
        $this->query['select'] = [$column];
        return array_column($this->get(), $column);
    }

    // SOFT DELETE

    public function withSoftDelete(): self
    {
        $this->softDeleteEnabled = true;
        return $this;
    }

    public function withTrashed(): self
    {
        $this->softDeleteEnabled = true;
        $this->withTrashed = true;
        return $this;
    }

    public function onlyTrashed(): self
    {
        $this->softDeleteEnabled = true;
        $this->onlyTrashed = true;
        return $this;
    }

    public function softDelete(): int
    {
        return $this->update(['deleted_at' => date('Y-m-d H:i:s')]);
    }

    public function restore(): int
    {
        return $this->update(['deleted_at' => null]);
    }

    // SCOPES

    public static function registerScope(string $name, callable $fn): void
    {
        self::$scopes[$name] = $fn;
    }

    public function scope(string ...$names): self
    {
        foreach ($names as $name) {
            if (!isset(self::$scopes[$name])) {
                throw new \RuntimeException("Scope no registrado: {$name}");
            }
            call_user_func(self::$scopes[$name], $this);
        }
        return $this;
    }

    // RAW

    public function raw(string $sql, array $bindings = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        return $stmt->fetchAll();
    }

    public function rawFirst(string $sql, array $bindings = []): ?array
    {
        $results = $this->raw($sql, $bindings);
        return $results[0] ?? null;
    }

    public function cloneQuery(): self
    {
        $clone = new self($this->pdo);
        $clone->table = $this->table;
        $clone->query = $this->query;
        $clone->bindings = $this->bindings;
        $clone->softDeleteEnabled = $this->softDeleteEnabled;
        $clone->withTrashed = $this->withTrashed;
        $clone->onlyTrashed = $this->onlyTrashed;
        return $clone;
    }

    // INTERNAL

    private function buildSelectQuery(): string
    {
        $this->applySoftDeleteScope();

        $sql = "SELECT " . implode(',', $this->query['select'] ?? ['*']);
        $sql .= " FROM {$this->table}";

        if (isset($this->query['joins'])) {
            $sql .= " " . implode(' ', $this->query['joins']);
        }

        if (isset($this->query['where'])) {
            $sql .= " WHERE " . implode(' ', $this->query['where']);
        }

        if (isset($this->query['group'])) {
            $sql .= " GROUP BY " . implode(',', $this->query['group']);
        }

        if (isset($this->query['having'])) {
            $sql .= " HAVING " . implode(' ', $this->query['having']);
        }

        if (isset($this->query['order'])) {
            $sql .= " ORDER BY " . implode(',', $this->query['order']);
        }

        if (isset($this->query['limit'])) {
            $sql .= " LIMIT " . $this->query['limit'];
            if (isset($this->query['offset'])) {
                $sql .= " OFFSET " . $this->query['offset'];
            }
        }

        return $sql;
    }

    private function applySoftDeleteScope(): void
    {
        if (!$this->softDeleteEnabled || $this->withTrashed) {
            return;
        }

        if ($this->onlyTrashed) {
            $this->whereNotNull('deleted_at');
        } else {
            $this->whereNull('deleted_at');
        }
    }

    private function validateOperator(string $operator): void
    {
        if (!in_array(strtoupper(trim($operator)), self::ALLOWED_OPERATORS, true)) {
            throw new \InvalidArgumentException("Operator not allowed: $operator");
        }
    }

    private function addBinding(mixed $value): string
    {
        $placeholder = ':param' . count($this->bindings);
        $this->bindings[$placeholder] = $value;
        return $placeholder;
    }

    private function execute(string $sql): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        $this->reset();
        return $stmt;
    }

    private function reset(): void
    {
        $this->query = [];
        $this->bindings = [];
    }

    // DEBUG

    public function toSql(): string
    {
        return $this->buildSelectQuery();
    }

    public function dd(): never
    {
        echo "SQL: " . $this->toSql() . "\n";
        echo "Bindings: " . json_encode($this->bindings, JSON_PRETTY_PRINT) . "\n";
        die();
    }
}
