<?php
declare(strict_types=1);
namespace Core\Middleware;

class SecurityHeadersMiddleware
{
    public static function handle($params, &$input, $context): bool
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=(), usb=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        // No same-origin: esta API sirve peticiones cross-origin a proposito (ver CorsMiddleware);
        // CORP en same-origin bloquearia esas mismas respuestas en navegadores que lo comprueban.
        header('Cross-Origin-Resource-Policy: cross-origin');


        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        // CSP personalizable vía parámetros
        if ($params) {
            header("Content-Security-Policy: {$params}");
        } else {
            header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'");
        }

        return true;
    }
}
