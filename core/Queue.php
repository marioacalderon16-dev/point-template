<?php
declare(strict_types=1);
namespace Core;

class Queue
{
    private static ?string $path = null;

    private static function basePath(): string
    {
        if (self::$path === null) {
            self::$path = (defined('BASE_PATH') ? BASE_PATH : getcwd()) . '/storage/jobs';
        }
        return self::$path;
    }

    private static function failedPath(): string
    {
        return self::basePath() . '/failed';
    }

    private static function ensureDirs(): void
    {
        $base = self::basePath();
        if (!is_dir($base)) mkdir($base, 0755, true);
        $failed = self::failedPath();
        if (!is_dir($failed)) mkdir($failed, 0755, true);
    }

    public static function dispatch(string $job, array $data = [], int $delay = 0, int $maxAttempts = 0): string
    {
        self::ensureDirs();

        if ($maxAttempts < 1) {
            $maxAttempts = self::resolveMaxAttempts($job);
        }

        $id = sprintf('%s_%s', str_replace('.', '', (string) microtime(true)), bin2hex(random_bytes(3)));
        $file = self::basePath() . "/{$id}.json";

        file_put_contents($file, json_encode([
            'id' => $id,
            'job' => $job,
            'data' => $data,
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
            'available_at' => time() + $delay,
            'created_at' => date('Y-m-d H:i:s'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $id;
    }

    public static function processNext(): ?array
    {
        self::ensureDirs();

        $files = glob(self::basePath() . '/*.json');
        sort($files);

        $now = time();

        foreach ($files as $file) {
            $job = json_decode((string) @file_get_contents($file), true);
            if (!$job || ($job['available_at'] ?? 0) > $now) continue;

            // Reclamo atómico: si rename falla, otro worker ya lo tomó
            $claimed = $file . '.processing';
            if (!@rename($file, $claimed)) continue;

            return self::execute($claimed, $job);
        }

        return null;
    }

    private static function execute(string $file, array $job): array
    {
        $class = $job['job'];
        $data = $job['data'] ?? [];
        $original = substr($file, 0, -strlen('.processing'));
        $job['attempts']++;

        try {
            if (!class_exists($class)) {
                throw new \RuntimeException("Clase no encontrada: {$class}");
            }

            $instance = Container::resolve($class);
            $instance->handle($data);

            unlink($file);
            Log::info('Job completed', ['id' => $job['id'], 'job' => $class]);
            return ['status' => 'ok', 'id' => $job['id'], 'job' => $class];

        } catch (\Throwable $e) {
            $job['last_error'] = $e->getMessage();
            $job['last_failed_at'] = date('Y-m-d H:i:s');
            $maxAttempts = $job['max_attempts'] ?? self::resolveMaxAttempts($class);

            if ($job['attempts'] >= $maxAttempts) {
                $job['failed_at'] = date('Y-m-d H:i:s');
                file_put_contents(self::failedPath() . '/' . basename($original), json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                unlink($file);

                Log::error('Job failed permanently', [
                    'id' => $job['id'], 'job' => $class,
                    'attempts' => $job['attempts'], 'error' => $e->getMessage(),
                ]);

                if (method_exists($class, 'failed')) {
                    try { (Container::resolve($class))->failed($data, $e); } catch (\Throwable $_) {}
                }

                return ['status' => 'failed', 'id' => $job['id'], 'job' => $class, 'error' => $e->getMessage()];
            }

            $backoff = self::calculateBackoff($class, $job['attempts']);
            $job['available_at'] = time() + $backoff;
            $job['retry_history'][] = [
                'attempt' => $job['attempts'],
                'error' => $e->getMessage(),
                'next_retry_in' => $backoff,
                'at' => date('Y-m-d H:i:s'),
            ];

            // Liberar el reclamo: reescribir y devolver a la cola
            file_put_contents($file, json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            rename($file, $original);

            Log::warning('Job retry scheduled', [
                'id' => $job['id'], 'job' => $class,
                'attempt' => $job['attempts'], 'backoff' => $backoff,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'retry', 'id' => $job['id'], 'job' => $class, 'attempt' => $job['attempts'], 'next_in' => $backoff, 'error' => $e->getMessage()];
        }
    }

    private static function resolveMaxAttempts(string $class): int
    {
        if (class_exists($class) && property_exists($class, 'maxAttempts')) {
            return (new \ReflectionClass($class))->getDefaultProperties()['maxAttempts'] ?? 3;
        }
        return 3;
    }

    private static function calculateBackoff(string $class, int $attempt): int
    {
        if (class_exists($class) && property_exists($class, 'backoff')) {
            $delays = (new \ReflectionClass($class))->getDefaultProperties()['backoff'] ?? [];
            if (is_array($delays) && $delays) {
                return $delays[$attempt - 1] ?? end($delays);
            }
        }

        $delay = (int) pow(2, $attempt) * 15;
        $jitter = random_int(0, 15);
        return min($delay + $jitter, 3600);
    }

    public static function pending(): array
    {
        self::ensureDirs();
        return self::readDir(self::basePath());
    }

    public static function failed(): array
    {
        self::ensureDirs();
        return self::readDir(self::failedPath());
    }

    public static function retry(): int
    {
        self::ensureDirs();

        $files = glob(self::failedPath() . '/*.json');
        $count = 0;

        foreach ($files as $file) {
            $job = json_decode(file_get_contents($file), true);
            if (!$job) continue;

            $job['attempts'] = 0;
            $job['available_at'] = time();
            unset($job['failed_at'], $job['last_error']);

            file_put_contents(self::basePath() . '/' . basename($file), json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            unlink($file);
            $count++;
        }

        return $count;
    }

    public static function flush(): int
    {
        $files = glob(self::failedPath() . '/*.json');
        foreach ($files as $file) unlink($file);
        return count($files);
    }

    private static function readDir(string $dir): array
    {
        // *.json excluye los reclamados (*.json.processing)
        $files = glob("{$dir}/*.json");
        $jobs = [];
        foreach ($files as $file) {
            $job = json_decode(file_get_contents($file), true);
            if ($job) $jobs[] = $job;
        }
        return $jobs;
    }
}
