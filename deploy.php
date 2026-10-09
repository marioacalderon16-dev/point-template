<?php

/**
 * Ejecuta migraciones y seeds desde el navegador (hosting sin consola).
 *
 * Uso:
 *   https://tu-dominio.com/deploy.php?key=TU_DEPLOY_KEY&action=migrate
 *
 * Acciones:
 *   migrate   — ejecutar migraciones pendientes
 *   seed      — ejecutar seeds pendientes
 *   rollback  — deshacer ultimo batch de migraciones
 *   status    — ver migraciones aplicadas
 *
 * Seguridad:
 *   Requiere DEPLOY_KEY en .env. Sin clave configurada, el script no hace nada.
 *   Elimina este archivo del servidor cuando no lo necesites.
 */

require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

header('Content-Type: text/plain; charset=utf-8');

$deployKey = $_ENV['DEPLOY_KEY'] ?? '';

if ($deployKey === '') {
    http_response_code(403);
    die("DEPLOY_KEY no configurada en .env\n");
}

if (($_GET['key'] ?? '') !== $deployKey) {
    http_response_code(403);
    die("Clave invalida.\n");
}

require __DIR__ . '/core/scribe/Table.php';
require __DIR__ . '/core/scribe/Runner.php';
require __DIR__ . '/core/scribe/Seeder.php';
require __DIR__ . '/core/scribe/SeedRunner.php';

$action = $_GET['action'] ?? 'status';

// Misma fuente que Core\DB (sin el fallback a sqlite: este script modifica una BD real,
// así que sin DB_DSN configurado debe fallar explícito en vez de adivinar una conexión.
if (($_ENV['DB_DSN'] ?? '') === '') {
    http_response_code(500);
    die("DB_DSN no configurada en .env\n");
}

$pdo = new PDO($_ENV['DB_DSN'], $_ENV['DB_USER'] ?? '', $_ENV['DB_PASS'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

ob_start();

try {
    switch ($action) {
        case 'migrate':
            $runner = new Core\Scribe\Runner($pdo, __DIR__ . '/database/migrations');
            $done = $runner->up();
            echo $done ? "$done migracion(es) aplicada(s).\n" : "Nada que migrar.\n";
            break;

        case 'rollback':
            $runner = new Core\Scribe\Runner($pdo, __DIR__ . '/database/migrations');
            $done = $runner->down();
            echo $done ? "$done tabla(s) revertida(s).\n" : "No hay migraciones para deshacer.\n";
            break;

        case 'seed':
            $seedDir = __DIR__ . '/database/seeds';
            if (!is_dir($seedDir)) {
                echo "No existe directorio de seeds.\n";
                break;
            }
            $runner = new Core\Scribe\SeedRunner($pdo, $seedDir);
            $done = $runner->run();
            echo $done ? "$done seed(s) ejecutado(s).\n" : "Todos los seeds ya fueron ejecutados.\n";
            break;

        case 'status':
            $stmt = $pdo->query("SELECT migration, batch, ran_at FROM migrations ORDER BY id");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rows)) {
                echo "No hay migraciones aplicadas.\n";
            } else {
                echo str_pad('MIGRACION', 50) . str_pad('BATCH', 8) . "FECHA\n";
                echo str_repeat('-', 80) . "\n";
                foreach ($rows as $r) {
                    echo str_pad($r['migration'], 50) . str_pad($r['batch'], 8) . ($r['ran_at'] ?? '') . "\n";
                }
            }
            break;

        default:
            echo "Accion desconocida: $action\n";
            echo "Acciones validas: migrate, rollback, seed, status\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo ob_get_clean();
