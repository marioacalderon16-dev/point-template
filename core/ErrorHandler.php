<?php
declare(strict_types=1);
namespace Core;

class ErrorHandler
{
    public static function register(): void
    {
        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
    }

    public static function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        $error = self::buildErrorStructure('PHP Error', $errstr, $errfile, $errline, [], $errno);
        self::sendJsonError($error, 500);
        return true;
    }

    public static function handleException(\Throwable $e): void
    {
        $code = $e->getCode();
        $httpCode = ($code >= 400 && $code < 600) ? $code : 500;
        $error = self::buildErrorStructure(get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTrace());
        self::sendJsonError($error, $httpCode);
    }

    private static function buildErrorStructure(
        string $type,
        string $message,
        string $file,
        int $line,
        array $trace = [],
        ?int $code = null
    ): array {
        error_log(sprintf("[%s] %s:%d  %s", date('c'), $file, $line, $message));

        if (self::isDevelopment()) {
            return [
                'error' => [
                    'type' => $type,
                    'message' => $message,
                    'where' => ['file' => $file, 'line' => $line],
                    'trace' => array_map(fn($s) => [
                        'file' => $s['file'] ?? 'unknown',
                        'line' => $s['line'] ?? 0,
                        'function' => ($s['class'] ?? '') . ($s['type'] ?? '') . ($s['function'] ?? ''),
                    ], $trace),
                ],
            ];
        }

        return [
            'error' => ['message' => 'Internal Server Error'],
        ];
    }

    private static function sendJsonError(array $error, int $statusCode): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit(1);
    }

    public static function isDevelopment(): bool
    {
        return ($_ENV['APP_ENV'] ?? 'development') === 'development';
    }
}
