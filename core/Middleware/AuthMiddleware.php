<?php
declare(strict_types=1);
namespace Core\Middleware;

use Core\Auth;
use Core\InputExtractor;

class AuthMiddleware
{
    public static function handle($params, &$input, $context): bool|array
    {
        $header = InputExtractor::header('authorization', '');

        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            self::deny('Token no proporcionado');
        }

        $claims = Auth::verify($matches[1]);
        if ($claims === null) {
            self::deny('Token invalido o expirado');
        }

        // auth:admin o auth:admin,editor exige que el claim 'role' (texto) o 'roles' (lista)
        // contenga al menos uno de los roles permitidos
        if ($params) {
            $allowedRoles = array_map('trim', explode(',', $params));
            if (array_intersect(Auth::rolesFromClaims($claims), $allowedRoles) === []) {
                self::deny('Permisos insuficientes', 403);
            }
        }

        return [
            '_user_id' => $claims['sub'] ?? null,
            '_user' => $claims,
        ];
    }

    private static function deny(string $message, int $code = 401): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        if ($code === 401) {
            header('WWW-Authenticate: Bearer');
        }
        echo json_encode(['status' => $code, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
