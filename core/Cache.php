<?php
declare(strict_types=1);
namespace Core;

final class Cache
{
    private static ?string $basePath = null;

    public static function configure(string $basePath): void
    {
        self::$basePath = rtrim($basePath, '/');
    }

    public static function get(string $key, ?int $ttl = null, ?callable $fallback = null): mixed
    {
        $file = self::path($key);

        if (file_exists($file)) {
            $entry = self::read($file);
            if ($entry !== null && ($entry['expires_at'] === 0 || $entry['expires_at'] > time())) {
                return $entry['value'];
            }
            @unlink($file); // otra petición puede haberlo borrado ya
        }

        if ($fallback !== null) {
            $value = $fallback();
            self::set($key, $value, $ttl ?? 300);
            return $value;
        }

        return null;
    }

    public static function set(string $key, mixed $value, int $ttl = 300): void
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $entry = [
            'value' => $value,
            'expires_at' => $ttl > 0 ? time() + $ttl : 0,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        file_put_contents(self::path($key), serialize($entry), LOCK_EX);
    }

    public static function has(string $key): bool
    {
        $file = self::path($key);
        if (!file_exists($file)) {
            return false;
        }

        $entry = self::read($file);
        if ($entry === null || ($entry['expires_at'] > 0 && $entry['expires_at'] <= time())) {
            @unlink($file);
            return false;
        }

        return true;
    }

    public static function forget(string $key): bool
    {
        $file = self::path($key);
        return file_exists($file) && unlink($file);
    }

    public static function flush(): int
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            return 0;
        }

        $files = glob($dir . '/*.cache');
        foreach ($files as $file) {
            unlink($file);
        }
        return count($files);
    }

    public static function increment(string $key, int $step = 1): int
    {
        $current = (int) (self::get($key) ?? 0);
        $new = $current + $step;

        $file = self::path($key);
        $entry = file_exists($file) ? self::read($file) : null;
        $expiresAt = $entry['expires_at'] ?? 0;
        $ttl = $expiresAt > 0 ? max(0, $expiresAt - time()) : 0;

        self::set($key, $new, $ttl);
        return $new;
    }

    public static function decrement(string $key, int $step = 1): int
    {
        return self::increment($key, -$step);
    }

    private static function dir(): string
    {
        return self::$basePath ?? (defined('BASE_PATH') ? BASE_PATH : getcwd()) . '/storage/cache';
    }

    private static function path(string $key): string
    {
        return self::dir() . '/' . md5($key) . '.cache';
    }

    private static function read(string $file): ?array
    {
        $content = @file_get_contents($file);
        if ($content === false) {
            return null;
        }

        // Sin objetos: un archivo de caché manipulado no puede instanciar clases (gadget chains)
        $entry = @unserialize($content, ['allowed_classes' => false]);
        return is_array($entry) && isset($entry['value'], $entry['expires_at']) ? $entry : null;
    }
}
