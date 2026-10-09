<?php
declare(strict_types=1);
namespace Core\Middleware;

class LoggerMiddleware
{
    private const SENSITIVE = ['password', 'password_confirmation', 'token', 'secret', 'api_key', 'authorization', 'cookie'];

    public static function handle($params, &$input, $context): bool
    {
        $level = $params ?: 'info';
        $logData = [
            'timestamp' => date('c'),
            'method' => $context['method'] ?? 'UNKNOWN',
            'path' => $context['path'] ?? 'unknown',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'input_size' => strlen(json_encode($input)),
        ];

        if ($level === 'debug') {
            $logData['input'] = self::redact($input);
            $logData['headers'] = self::redact(array_change_key_case(getallheaders() ?: []));
        }

        error_log("[{$level}] REQUEST: " . json_encode($logData, JSON_UNESCAPED_UNICODE));

        return true;
    }

    private static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE, true)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }
        return $data;
    }
}
