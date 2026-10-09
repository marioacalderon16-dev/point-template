<?php
declare(strict_types=1);

namespace Core\Scribe;

class Table
{
    private string $name;
    private string $mode;
    private array $columns = [];
    private array $indexes = [];
    private array $drops = [];
    private ?string $pending = null;
    private ?string $current = null;

    public static function create(string $name): self
    {
        $t = new self();
        $t->name = $name;
        $t->mode = 'create';
        return $t;
    }

    public static function alter(string $name): self
    {
        $t = new self();
        $t->name = $name;
        $t->mode = 'alter';
        return $t;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    // ── Column types ──

    public function id(string $name = 'id'): self
    {
        return $this->addCol($name, 'id');
    }

    public function str(string|int $nameOrLen = 255, int $length = 255): self
    {
        if ($this->pending !== null) {
            $name = $this->pending;
            $length = is_int($nameOrLen) ? $nameOrLen : $length;
            $this->pending = null;
        } else {
            $name = is_string($nameOrLen) ? $nameOrLen : 'string_col';
        }
        return $this->addCol($name, 'varchar', ['length' => $length]);
    }

    public function text(string|null $name = null): self
    {
        return $this->addCol($this->resolveName($name), 'text');
    }

    public function int(string|null $name = null): self
    {
        return $this->addCol($this->resolveName($name), 'integer');
    }

    public function bigInt(string|null $name = null): self
    {
        return $this->addCol($this->resolveName($name), 'bigint');
    }

    public function decimal(string|null $name = null, int $precision = 10, int $scale = 2): self
    {
        return $this->addCol($this->resolveName($name), 'decimal', ['precision' => $precision, 'scale' => $scale]);
    }

    public function bool(string|null $name = null): self
    {
        return $this->addCol($this->resolveName($name), 'boolean');
    }

    public function date(string|null $name = null): self
    {
        return $this->addCol($this->resolveName($name), 'date');
    }

    public function timestamp(string|null $name = null): self
    {
        return $this->addCol($this->resolveName($name), 'timestamp');
    }

    public function json(string|null $name = null): self
    {
        return $this->addCol($this->resolveName($name), 'json');
    }

    public function uuid(string|null $name = null): self
    {
        return $this->addCol($this->resolveName($name), 'uuid');
    }

    public function enum(string $name, array $values): self
    {
        return $this->addCol($name, 'enum', ['values' => $values]);
    }

    // ── Shortcuts ──

    public function stamp(): self
    {
        $this->addCol('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP']);
        $this->addCol('updated_at', 'timestamp', ['nullable' => true]);
        return $this;
    }

    public function softDelete(): self
    {
        return $this->addCol('deleted_at', 'timestamp', ['nullable' => true]);
    }

    // ── Modifiers ──

    public function nullable(): self
    {
        if ($this->current) $this->columns[$this->current]['nullable'] = true;
        return $this;
    }

    public function default(mixed $value): self
    {
        if ($this->current) $this->columns[$this->current]['default'] = $value;
        return $this;
    }

    public function unique(): self
    {
        if ($this->current) $this->columns[$this->current]['unique'] = true;
        return $this;
    }

    public function index(): self
    {
        if ($this->current) $this->indexes[] = $this->current;
        return $this;
    }

    public function references(string $table, string $column = 'id'): self
    {
        if ($this->current) {
            $this->columns[$this->current]['references'] = ['table' => $table, 'column' => $column];
        }
        return $this;
    }

    // ── Alter helpers ──

    public function addColumn(string $name): self
    {
        $this->pending = $name;
        return $this;
    }

    public function dropColumn(string $name): self
    {
        $this->drops[] = $name;
        return $this;
    }

    // ── SQL generation ──

    public function toSql(string $driver): string
    {
        return $this->mode === 'create'
            ? $this->createSql($driver)
            : $this->alterSql($driver);
    }

    public function getDownSql(string $driver): string
    {
        if ($this->mode === 'create') {
            return "DROP TABLE IF EXISTS {$this->name}";
        }

        $parts = [];
        foreach (array_keys($this->columns) as $col) {
            $parts[] = "ALTER TABLE {$this->name} DROP COLUMN {$col}";
        }
        return implode(";\n", $parts);
    }

    // ── Internal ──

    private function resolveName(?string $name): string
    {
        if ($this->pending !== null) {
            $resolved = $this->pending;
            $this->pending = null;
            return $resolved;
        }
        return $name ?? 'column';
    }

    private function addCol(string $name, string $type, array $extra = []): self
    {
        $this->columns[$name] = array_merge(['name' => $name, 'type' => $type], $extra);
        $this->current = $name;
        return $this;
    }

    private function createSql(string $driver): string
    {
        $lines = [];

        foreach ($this->columns as $col) {
            $lines[] = '    ' . $this->columnSql($col, $driver);
        }

        foreach ($this->columns as $col) {
            if (!empty($col['unique'])) {
                $lines[] = "    UNIQUE ({$col['name']})";
            }
            if (!empty($col['references'])) {
                $ref = $col['references'];
                $lines[] = "    FOREIGN KEY ({$col['name']}) REFERENCES {$ref['table']}({$ref['column']})";
            }
        }

        $sql = "CREATE TABLE IF NOT EXISTS {$this->name} (\n" . implode(",\n", $lines) . "\n)";

        if ($driver === 'mysql') {
            $sql .= " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        }

        $indexSql = $this->indexesSql($driver);
        return $indexSql ? "$sql;\n$indexSql" : $sql;
    }

    private function alterSql(string $driver): string
    {
        $parts = [];

        foreach ($this->drops as $col) {
            $parts[] = "ALTER TABLE {$this->name} DROP COLUMN {$col}";
        }

        foreach ($this->columns as $col) {
            $parts[] = "ALTER TABLE {$this->name} ADD COLUMN " . $this->columnSql($col, $driver);
        }

        $indexSql = $this->indexesSql($driver);
        if ($indexSql) $parts[] = $indexSql;

        return implode(";\n", $parts);
    }

    private function columnSql(array $col, string $driver): string
    {
        $name = $col['name'];
        $type = $this->mapType($col, $driver);

        if ($col['type'] === 'id') {
            return $driver === 'pgsql'
                ? "$name BIGSERIAL PRIMARY KEY"
                : "$name BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY";
        }

        $nullable = !empty($col['nullable']) ? 'NULL' : 'NOT NULL';
        $default = '';

        if (array_key_exists('default', $col)) {
            $val = $col['default'];
            if ($val === 'CURRENT_TIMESTAMP' || $val === 'NOW()') {
                $default = $driver === 'pgsql' ? 'DEFAULT NOW()' : 'DEFAULT CURRENT_TIMESTAMP';
            } elseif (is_bool($val)) {
                $default = 'DEFAULT ' . ($val ? 'TRUE' : 'FALSE');
            } elseif (is_null($val)) {
                $default = 'DEFAULT NULL';
            } elseif (is_numeric($val)) {
                $default = "DEFAULT $val";
            } else {
                $default = "DEFAULT '$val'";
            }
        }

        return trim("$name $type $nullable $default");
    }

    private function mapType(array $col, string $driver): string
    {
        $isPg = $driver === 'pgsql';
        $length = $col['length'] ?? 255;
        $precision = $col['precision'] ?? 10;
        $scale = $col['scale'] ?? 2;

        return match ($col['type']) {
            'varchar' => "VARCHAR($length)",
            'text' => 'TEXT',
            'integer' => 'INTEGER',
            'bigint' => 'BIGINT',
            'decimal' => "DECIMAL($precision,$scale)",
            'boolean' => $isPg ? 'BOOLEAN' : 'TINYINT(1)',
            'date' => 'DATE',
            'timestamp' => 'TIMESTAMP',
            'json' => $isPg ? 'JSONB' : 'JSON',
            'uuid' => $isPg ? 'UUID' : 'CHAR(36)',
            'enum' => $this->enumType($col, $driver),
            default => 'TEXT',
        };
    }

    private function enumType(array $col, string $driver): string
    {
        $values = $col['values'] ?? [];
        $quoted = array_map(fn($v) => "'$v'", $values);

        if ($driver === 'pgsql') {
            $check = implode(', ', $quoted);
            return "VARCHAR(50) CHECK ({$col['name']} IN ($check))";
        }

        return "ENUM(" . implode(', ', $quoted) . ")";
    }

    private function indexesSql(string $driver): string
    {
        $parts = [];
        foreach ($this->indexes as $col) {
            $idxName = "idx_{$this->name}_{$col}";
            $parts[] = "CREATE INDEX $idxName ON {$this->name} ($col)";
        }
        return implode(";\n", $parts);
    }
}
