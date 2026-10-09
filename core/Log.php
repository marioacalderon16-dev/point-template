<?php
declare(strict_types=1);
namespace Core;

final class Log
{
    private const LEVELS = ['debug', 'info', 'warning', 'error'];

    private static ?string $basePath = null;
    private static string $minLevel = 'debug';

    public static function configure(string $basePath, string $minLevel = 'debug'): void
    {
        self::$basePath = rtrim($basePath, '/');
        self::$minLevel = $minLevel;
    }

    public static function debug(string $message, array $context = []): void
    {
        self::write('debug', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        if (array_search($level, self::LEVELS) < array_search(self::$minLevel, self::LEVELS)) {
            return;
        }

        $dir = self::$basePath ?? (defined('BASE_PATH') ? BASE_PATH : getcwd()) . '/storage/logs';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file = $dir . '/' . date('Y-m-d') . '.log';
        $timestamp = date('Y-m-d H:i:s');
        $tag = strtoupper($level);
        $ctx = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';

        $line = "[{$timestamp}] {$tag}: {$message}{$ctx}" . PHP_EOL;

        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}
