<?php
declare(strict_types=1);

namespace Core\Scribe;

class SeedRunner
{
    private \PDO $pdo;
    private string $dir;
    private Seeder $seeder;

    public function __construct(\PDO $pdo, string $dir)
    {
        $this->pdo = $pdo;
        $this->dir = $dir;
        $this->seeder = new Seeder($pdo);
        $this->ensureTable();
    }

    public function run(): int
    {
        $applied = $this->applied();
        $files = $this->files();
        $count = 0;

        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $applied)) continue;

            $seed = require $file;

            if (!is_array($seed) || empty($seed['table']) || empty($seed['data'])) {
                echo "  Saltando $name (formato invalido)\n";
                continue;
            }

            $this->seeder->insert($seed['table'], $seed['data']);

            $this->pdo->prepare("INSERT INTO seeds (seed) VALUES (?)")->execute([$name]);

            echo "  Seed ejecutado: $name (" . count($seed['data']) . " registros)\n";
            $count++;
        }

        return $count;
    }

    private function applied(): array
    {
        return $this->pdo->query("SELECT seed FROM seeds ORDER BY id")
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function files(): array
    {
        if (!is_dir($this->dir)) return [];
        $files = glob($this->dir . '/*.seed.php');
        sort($files);
        return $files;
    }

    private function ensureTable(): void
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $idCol = $driver === 'pgsql' ? 'BIGSERIAL PRIMARY KEY' : 'BIGINT AUTO_INCREMENT PRIMARY KEY';

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS seeds (
            id $idCol,
            seed VARCHAR(255) NOT NULL,
            ran_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    }
}
