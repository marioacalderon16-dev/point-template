<?php
declare(strict_types=1);
namespace Core;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

final class Auth
{
    /** Valida JWT_SECRET sin emitir/verificar nada; para chequeos fail-fast al arrancar. */
    public static function assertConfigured(): void
    {
        self::secret();
    }

    public static function issue(array $claims, ?int $ttl = null): string
    {
        $now = time();
        $payload = array_merge($claims, [
            'iat' => $now,
            'exp' => $now + ($ttl ?? (int) ($_ENV['JWT_TTL'] ?? 3600)),
        ]);

        return JWT::encode($payload, self::secret(), 'HS256');
    }

    public static function verify(string $token): ?array
    {
        try {
            return (array) JWT::decode($token, new Key(self::secret(), 'HS256'));
        } catch (\Throwable) {
            return null;
        }
    }

    /** ID del usuario autenticado (claim 'sub'). Solo en rutas con el middleware auth. */
    public static function id(array $input): mixed
    {
        return self::user($input)['sub'] ?? null;
    }

    /** Claims del token del usuario autenticado. Solo en rutas con el middleware auth. */
    public static function user(array $input): array
    {
        if (!isset($input['_user']) || !is_array($input['_user'])) {
            throw new \RuntimeException('Auth::id()/user() en una ruta sin el middleware auth (usa un grupo con token, p. ej. protected).');
        }
        return $input['_user'];
    }

    /** @return list<string> roles del usuario autenticado ('role' y 'roles' del token) */
    public static function roles(array $input): array
    {
        return self::rolesFromClaims(self::user($input));
    }

    /** true si el usuario autenticado tiene al menos uno de los roles indicados. */
    public static function hasRole(array $input, string ...$roles): bool
    {
        return array_intersect(self::roles($input), $roles) !== [];
    }

    /**
     * Roles de unos claims: 'role' (texto) y 'roles' (lista de textos).
     * @return list<string>
     */
    public static function rolesFromClaims(array $claims): array
    {
        $roles = is_string($claims['role'] ?? null) ? [$claims['role']] : [];
        if (is_array($claims['roles'] ?? null)) {
            foreach ($claims['roles'] as $role) {
                if (is_string($role)) {
                    $roles[] = $role;
                }
            }
        }
        return $roles;
    }

    private static function secret(): string
    {
        $secret = $_ENV['JWT_SECRET'] ?? '';
        if (strlen($secret) < 32) {
            throw new \RuntimeException('JWT_SECRET debe tener al menos 32 caracteres. Configuralo en .env');
        }
        return $secret;
    }
}
