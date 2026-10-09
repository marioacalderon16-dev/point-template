<?php
declare(strict_types=1);
namespace Core;

/**
 * Ejecuta en la cola una ruta con ->asyncable()/->async() y guarda el resultado en la caché
 * (clave job:{id}) para GET /jobs/{id}. Permisos y validación ya se comprobaron al encolar.
 */
final class AsyncEndpointJob
{
    public function handle(array $payload): void
    {
        $key = 'job:' . $payload['job_id'];
        $keep = (int) $payload['keep'];
        // Relee antes de guardar: Job::progress() puede haber escrito mientras tanto
        $save = function (array $changes) use ($key, $keep): void {
            Cache::set($key, array_merge((array) (Cache::get($key) ?? []), $changes, ['keep' => $keep]), $keep);
        };
        $save(['state' => 'running', 'started_at' => date('c')]);

        Job::$current = $payload['job_id'];
        try {
            $route = self::findRoute($payload);
            $context = ['method' => $payload['method'], 'path' => $payload['path'], 'route' => $route, 'visited' => []];
            $response = Endpoint::execute($route, $payload['data'], $context);
            $status = is_array($response) && is_int($response['status'] ?? null) ? $response['status'] : 200;
            $save([
                'state' => 'done',
                'finished_at' => date('c'),
                'result_status' => $status,
                'result' => is_string($response) ? $response : json_decode((string) json_encode($response), true),
            ]);
        } catch (\Throwable $e) {
            Log::error('async.failed', ['job' => $payload['job_id'], 'path' => $payload['path'], 'error' => $e->getMessage()]);
            $save([
                'state' => 'failed',
                'finished_at' => date('c'),
                'error' => ErrorHandler::isDevelopment() ? $e->getMessage() : 'Ocurrió un error',
            ]);
        } finally {
            Job::$current = null;
        }
    }

    /** El worker arranca sin rutas (POINT_NO_ROUTES): carga el archivo del endpoint si hace falta. */
    private static function findRoute(array $payload): array
    {
        $registry = RouteRegistry::getInstance();
        $match = function () use ($registry, $payload): ?array {
            foreach ($registry->getRoutes() as $route) {
                if ($route['path'] === $payload['path'] && in_array($payload['method'], $route['methods'], true)) {
                    return $route;
                }
            }
            return null;
        };
        $route = $match();
        if ($route === null && is_string($payload['file']) && is_file($payload['file'])) {
            require_once $payload['file'];
            $route = $match();
        }
        if ($route === null) {
            throw new \RuntimeException("Ruta no encontrada para el trabajo: {$payload['method']} {$payload['path']}");
        }
        return $route;
    }
}
