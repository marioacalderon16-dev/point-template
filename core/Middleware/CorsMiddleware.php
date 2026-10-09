<?php
declare(strict_types=1);
namespace Core\Middleware;

class CorsMiddleware
{
    public static function handle($params, &$input, $context): bool
    {
        // Sin CORS_ORIGINS explicito: '*' solo en development. Fuera de ahi, ningun origen
        // por defecto (nada de Access-Control-Allow-Origin) hasta que se configure a proposito.
        $isDev = \Core\ErrorHandler::isDevelopment();
        $configured = $params ?: ($_ENV['CORS_ORIGINS'] ?? ($isDev ? '*' : ''));
        $origins = array_map('trim', explode(',', $configured));
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $wildcard = in_array('*', $origins, true);

        // Con wildcard nunca se envian credentials: * + credentials permite
        // a cualquier sitio hacer peticiones autenticadas con las cookies del usuario
        if ($wildcard) {
            header('Access-Control-Allow-Origin: *');
        } elseif ($origin !== '' && in_array($origin, $origins, true)) {
            header("Access-Control-Allow-Origin: {$origin}");
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Expose-Headers: X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset');

        if (($context['method'] ?? '') === 'OPTIONS') {
            header('Access-Control-Max-Age: 86400');
            http_response_code(204);
            exit;
        }

        return true;
    }
}
