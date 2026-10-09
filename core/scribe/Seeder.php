<?php
declare(strict_types=1);

namespace Core\Scribe;

class Seeder
{
    protected \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function insert(string $table, array $rows): int
    {
        if (empty($rows)) return 0;

        $columns = array_keys($rows[0]);
        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $table,
            implode(', ', $columns),
            implode(', ', array_fill(0, count($rows), $placeholders))
        );

        $values = [];
        foreach ($rows as $row) {
            foreach ($columns as $col) {
                $values[] = $row[$col] ?? null;
            }
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);

        return count($rows);
    }

    public function truncate(string $table): void
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            $this->pdo->exec("TRUNCATE TABLE $table RESTART IDENTITY CASCADE");
        } else {
            $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            $this->pdo->exec("TRUNCATE TABLE $table");
            $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        }
    }
}
