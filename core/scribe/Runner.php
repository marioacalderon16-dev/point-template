<?php
declare(strict_types=1);

namespace Core\Scribe;

class Runner
{
    private \PDO $pdo;
    private string $dir;
    private string $driver;

    public function __construct(\PDO $pdo, string $dir)
    {
        $this->pdo = $pdo;
        $this->dir = $dir;
        $this->driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $this->ensureTable();
    }

    public function up(): int
    {
        $applied = $this->applied();
        $files = $this->files();
        $batch = $this->nextBatch();
        $count = 0;

        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $applied)) continue;

            $table = require $file;

            if (!$table instanceof Table) {
                echo "  Saltando $name (no retorna Table)\n";
                continue;
            }

            $sql = $table->toSql($this->driver);

            foreach (array_filter(explode(";\n", $sql)) as $statement) {
                $this->pdo->exec(trim($statement));
            }

            $this->pdo->prepare("INSERT INTO migrations (migration, batch) VALUES (?, ?)")
                ->execute([$name, $batch]);

            echo "  Migrado: $name\n";
            $count++;
        }

        return $count;
    }

    public function down(): int
    {
        $batch = $this->lastBatch();
        if ($batch === 0) return 0;

        $stmt = $this->pdo->prepare("SELECT migration FROM migrations WHERE batch = ? ORDER BY id DESC");
        $stmt->execute([$batch]);
        $migrations = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $count = 0;

        foreach ($migrations as $name) {
            $file = $this->dir . '/' . $name;
            if (!file_exists($file)) {
                echo "  Archivo no encontrado: $name\n";
                continue;
            }

            $table = require $file;

            if (!$table instanceof Table) continue;

            $sql = $table->getDownSql($this->driver);

            foreach (array_filter(explode(";\n", $sql)) as $statement) {
                $this->pdo->exec(trim($statement));
            }

            $this->pdo->prepare("DELETE FROM migrations WHERE migration = ?")->execute([$name]);

            echo "  Revertido: $name\n";
            $count++;
        }

        return $count;
    }

    private function applied(): array
    {
        return $this->pdo->query("SELECT migration FROM migrations ORDER BY id")
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function files(): array
    {
        if (!is_dir($this->dir)) return [];
        $files = glob($this->dir . '/*.table.php');
        sort($files);
        return $files;
    }

    private function nextBatch(): int
    {
        return $this->lastBatch() + 1;
    }

    private function lastBatch(): int
    {
        $result = $this->pdo->query("SELECT MAX(batch) FROM migrations")->fetchColumn();
        return (int) $result;
    }

    private function ensureTable(): void
    {
        $idCol = $this->driver === 'pgsql' ? 'BIGSERIAL PRIMARY KEY' : 'BIGINT AUTO_INCREMENT PRIMARY KEY';

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
            id $idCol,
            migration VARCHAR(255) NOT NULL,
            batch INTEGER NOT NULL,
            ran_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    }
}
