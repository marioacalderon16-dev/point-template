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

    private static function secret(): string
    {
        $secret = $_ENV['JWT_SECRET'] ?? '';
        if (strlen($secret) < 32) {
            throw new \RuntimeException('JWT_SECRET debe tener al menos 32 caracteres. Configuralo en .env');
        }
        return $secret;
    }
}
