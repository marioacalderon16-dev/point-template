<?php
namespace App\Services;

use Core\Container;
use Core\Log;
use Core\Queue;

/** Encola un job; con QUEUE_SYNC=true lo ejecuta en línea (desarrollo sin worker). */
final class Async
{
    public static function run(string $job, array $data = []): void
    {
        if (filter_var($_ENV['QUEUE_SYNC'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            try {
                Container::resolve($job)->handle($data);
            } catch (\Throwable $e) {
                Log::error('async.sync_failed', ['job' => $job, 'error' => $e->getMessage()]);
            }
            return;
        }
        Queue::dispatch($job, $data);
    }
}
