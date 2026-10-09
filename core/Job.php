<?php
declare(strict_types=1);
namespace Core;

/** Utilidades para el trabajo asíncrono en curso (rutas con ->asyncable() o ->async()). */
final class Job
{
    /** ID del trabajo asíncrono que se está ejecutando (lo fija AsyncEndpointJob). */
    public static ?string $current = null;

    /** Informa del avance (0-100), visible en GET /jobs/{id} como "progress". Fuera de un trabajo no hace nada. */
    public static function progress(int $percent): void
    {
        if (self::$current === null) {
            return;
        }
        $key = 'job:' . self::$current;
        $entry = Cache::get($key);
        if (is_array($entry)) {
            $entry['progress'] = max(0, min(100, $percent));
            Cache::set($key, $entry, (int) ($entry['keep'] ?? 86400));
        }
    }
}
