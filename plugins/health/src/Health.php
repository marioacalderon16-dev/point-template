<?php
namespace Plugins\Health;

use Core\DB;
use Core\ErrorHandler;
use Core\Log;

final class Health
{
    public static function check(): array
    {
        $checks = [];
        $allOk = true;

        $checks['database'] = self::checkDatabase();
        $checks['disk'] = self::checkDisk();
        $checks['queue'] = self::checkQueue();

        foreach ($checks as $check) {
            if ($check['status'] !== 'ok') {
                $allOk = false;
                break;
            }
        }

        // /health es público: fuera de development solo se muestra el estado de cada check
        // (sin mensajes de error de la BD, espacio en disco ni tamaño de la cola)
        if (!ErrorHandler::isDevelopment()) {
            $checks = array_map(fn (array $check) => ['status' => $check['status']], $checks);
        }

        return [
            'status' => $allOk ? 'ok' : 'degraded',
            'timestamp' => date('c'),
            'checks' => $checks,
        ];
    }

    private static function checkDatabase(): array
    {
        try {
            $start = microtime(true);
            DB::raw('SELECT 1');
            $ms = round((microtime(true) - $start) * 1000, 1);

            return ['status' => 'ok', 'latency_ms' => $ms];
        } catch (\Throwable $e) {
            Log::error('health.database', ['error' => $e->getMessage()]);
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    private static function checkDisk(): array
    {
        $path = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $freeBytes = @disk_free_space($path);

        if ($freeBytes === false) {
            return ['status' => 'error', 'error' => 'No se pudo leer espacio en disco'];
        }

        $freeGb = round($freeBytes / 1073741824, 2);
        $status = $freeGb > 1 ? 'ok' : ($freeGb > 0.1 ? 'warning' : 'error');

        return ['status' => $status, 'free_gb' => $freeGb];
    }

    private static function checkQueue(): array
    {
        $basePath = (defined('BASE_PATH') ? BASE_PATH : getcwd()) . '/storage/jobs';

        $pending = is_dir($basePath) ? count(glob($basePath . '/*.json')) : 0;
        $failedDir = $basePath . '/failed';
        $failed = is_dir($failedDir) ? count(glob($failedDir . '/*.json')) : 0;

        $status = $failed > 10 ? 'warning' : 'ok';

        return ['status' => $status, 'pending' => $pending, 'failed' => $failed];
    }
}
