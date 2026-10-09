<?php
declare(strict_types=1);
namespace Core\Middleware;

/**
 * Limitador de peticiones por ventana fija.
 *
 * Uso en rutas: 'rate_limit:60' (60 peticiones/min por usuario autenticado o por IP).
 * Otros middlewares pueden usar hit() con sus propias claves (teléfono, IP, etc.).
 *
 * Almacenamiento: contadores en storage/ratelimit/<shard>/ con flock (atómico dentro de
 * una instancia). Tope RATE_LIMIT_MAX_FILES (100000 por defecto) y limpieza programada
 * en config/schedule.php. No se comparte entre réplicas: con varias instancias el límite
 * efectivo se multiplica por el número de réplicas.
 */
class RateLimitMiddleware
{
    /** Ventana más larga en uso (verification_start: 10 por día); respaldo si el archivo no se puede leer */
    private const MAX_WINDOW = 86400;

    public static function handle($params, &$input, $context): bool
    {
        $limit = (int) ($params ?: 60);
        $window = 60; // 1 minuto

        // Cliente: usuario autenticado (public_id UUID, se usa como cadena) o IP
        $userId = $input['_user_id'] ?? ($input['usuario']['id'] ?? null);
        $who = ($userId !== null && $userId !== '')
            ? 'u:' . (string) $userId
            : 'ip:' . self::resolveClientIp();

        $endpoint = $context['path'] ?? 'unknown';
        $retryAfter = self::hit("route|{$endpoint}|{$who}", $limit, $window);
        if ($retryAfter !== null) {
            self::sendTooManyRequests($retryAfter, $limit);
        }

        return true;
    }

    /**
     * Cuenta un intento para $key. Devuelve null si está dentro del límite o los
     * segundos que faltan para que se reinicie la ventana si lo excede.
     *
     * Almacenamiento lleno: con $overflow la clave nueva se cuenta en un bucket compartido
     * ($overflow + hash(key) % 1024; archivos acotados y reutilizables) y se registra una alerta.
     * Sin almacenamiento: $strict lanza \RuntimeException (auth, fail-closed); si no, deja
     * pasar (fail-open, solo el rate_limit genérico).
     */
    public static function hit(string $key, int $limit, int $window, ?string $overflow = null, bool $strict = false): ?int
    {
        $file = self::file($key, true);
        if ($file !== null && !is_file($file) && !self::hasRoom(dirname($file))) {
            $file = $overflow !== null ? self::overflowFile($overflow, $key, true) : null;
            if ($file !== null) {
                error_log('RateLimitMiddleware ALERTA: almacenamiento lleno, usando bucket overflow');
            }
        }

        $fh = $file !== null ? @fopen($file, 'c+') : false;
        if ($fh === false) {
            if ($strict) {
                throw new \RuntimeException('rate limit sin almacenamiento');
            }
            error_log('RateLimitMiddleware: no se pudo contar, se deja pasar');
            return null;
        }

        try {
            flock($fh, LOCK_EX);
            $now = time();
            $data = json_decode(stream_get_contents($fh) ?: '', true);
            if (!is_array($data) || ($data['reset'] ?? 0) <= $now) {
                $data = ['count' => 0, 'reset' => $now + $window];
            }

            $data['count']++;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($data));
            fflush($fh);

            return $data['count'] > $limit ? max(1, $data['reset'] - $now) : null;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * Consulta sin contar: retryAfter si $key ya alcanzó $limit en su ventana, si no null.
     * Si la clave no tiene archivo porque el almacenamiento está lleno, consulta su bucket overflow.
     */
    public static function peek(string $key, int $limit, ?string $overflow = null): ?int
    {
        $file = self::file($key, false);
        if ($overflow !== null && !is_file($file) && is_dir(dirname($file)) && !self::hasRoom(dirname($file), false)) {
            $file = self::overflowFile($overflow, $key, false);
        }
        $data = $file !== null && is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        $now = time();
        if (!is_array($data) || ($data['reset'] ?? 0) <= $now) {
            return null;
        }
        return ($data['count'] ?? 0) >= $limit ? max(1, $data['reset'] - $now) : null;
    }

    /**
     * Limpieza programada (config/schedule.php): borra contadores vencidos.
     * Vencido = su ventana ('reset') ya pasó. Devuelve cuántos borró.
     */
    public static function purgeExpired(): int
    {
        $removed = 0;
        $now = time();
        foreach (glob(self::dir() . '/*', GLOB_ONLYDIR) ?: [] as $shard) {
            if (basename($shard) === 'overflow') {
                continue; // buckets acotados y reutilizables: no se borran
            }
            foreach (scandir($shard) ?: [] as $f) {
                $path = $shard . '/' . $f;
                if (str_ends_with($f, '.json') && self::isExpired($path, $now) && @unlink($path)) {
                    $removed++;
                }
            }
        }
        return $removed;
    }

    /** 429 uniforme: {status, message, retryAfter} + cabecera Retry-After. */
    public static function sendTooManyRequests(int $retryAfter, ?int $limit = null): never
    {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        header('Retry-After: ' . $retryAfter);
        if ($limit !== null) {
            header('X-RateLimit-Limit: ' . $limit);
            header('X-RateLimit-Remaining: 0');
        }

        echo json_encode([
            'status' => 429,
            'message' => 'Demasiados intentos, espera un momento',
            'retryAfter' => $retryAfter,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * IP del cliente. X-Forwarded-For solo se usa si REMOTE_ADDR está en TRUSTED_PROXIES
     * (vacío por defecto → siempre REMOTE_ADDR). Se recorre de derecha a izquierda y se
     * toma la primera IP que no sea un proxy de confianza (la izquierda la controla el cliente).
     */
    public static function resolveClientIp(): string
    {
        $trustedProxies = array_filter(
            array_map('trim', explode(',', $_ENV['TRUSTED_PROXIES'] ?? ''))
        );
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        if (!empty($trustedProxies) && self::ipInList($remoteAddr, $trustedProxies)) {
            $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            $ips = array_reverse(array_filter(array_map('trim', explode(',', $forwarded))));
            foreach ($ips as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                    break; // cadena manipulada: se usa REMOTE_ADDR
                }
                if (!self::ipInList($ip, $trustedProxies)) {
                    return $ip;
                }
            }
        }

        return $remoteAddr;
    }

    /** Coincidencia exacta o por rango CIDR (IPv4/IPv6). */
    private static function ipInList(string $ip, array $list): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        foreach ($list as $entry) {
            if (!str_contains($entry, '/')) {
                if ($entry === $ip) {
                    return true;
                }
                continue;
            }
            [$net, $bits] = explode('/', $entry, 2);
            $netBin = @inet_pton($net);
            if ($netBin === false || strlen($netBin) !== strlen($bin) || !ctype_digit($bits)) {
                continue;
            }
            $bits = (int) $bits;
            $bytes = intdiv($bits, 8);
            $rem = $bits % 8;
            if (substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
                continue;
            }
            if ($rem === 0) {
                return true;
            }
            $mask = chr((0xFF << (8 - $rem)) & 0xFF);
            if ((($bin[$bytes] ?? "\0") & $mask) === (($netBin[$bytes] ?? "\0") & $mask)) {
                return true;
            }
        }
        return false;
    }

    private static function dir(): string
    {
        $base = $_ENV['RATE_LIMIT_DIR'] ?? '';
        if ($base !== '') {
            return rtrim($base, '/');
        }
        return (defined('BASE_PATH') ? BASE_PATH . '/storage' : sys_get_temp_dir()) . '/ratelimit';
    }

    /**
     * Ruta del contador: la clave se guarda como hash (no quedan teléfonos ni IPs en
     * nombres de archivo) y se reparte en 256 shards para acotar el costo del tope.
     */
    private static function file(string $key, bool $create): ?string
    {
        $hash = hash('sha256', $key);
        $shard = self::dir() . '/' . substr($hash, 0, 2);
        if ($create && !is_dir($shard) && !@mkdir($shard, 0755, true) && !is_dir($shard)) {
            error_log('RateLimitMiddleware: no se pudo crear ' . $shard);
            return null;
        }
        return $shard . '/' . $hash . '.json';
    }

    /**
     * Archivo del bucket overflow: $prefix + (hash(key) % 1024). Como mucho 1024 archivos
     * por prefijo, en storage/ratelimit/overflow (no crece con claves nuevas).
     */
    private static function overflowFile(string $prefix, string $key, bool $create): ?string
    {
        $dir = self::dir() . '/overflow';
        if ($create && !is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }
        $bucket = hexdec(substr(hash('sha256', $key), 0, 8)) % 1024;
        return $dir . '/' . hash('sha256', $prefix . $bucket) . '.json';
    }

    /** Hay espacio en el shard; si no (y $purge), intenta liberar vencidos solo en ese shard. */
    private static function hasRoom(string $shard, bool $purge = true): bool
    {
        $max = max(1, intdiv((int) ($_ENV['RATE_LIMIT_MAX_FILES'] ?? 100000), 256));
        $files = array_values(array_diff(scandir($shard) ?: [], ['.', '..']));
        if (count($files) < $max) {
            return true;
        }
        if (!$purge) {
            return false;
        }
        $now = time();
        foreach ($files as $f) {
            if (self::isExpired($shard . '/' . $f, $now)) {
                @unlink($shard . '/' . $f);
            }
        }
        clearstatcache();
        return count(array_diff(scandir($shard) ?: [], ['.', '..'])) < $max;
    }

    /** El contador ya no limita: su 'reset' pasó (o, si no se puede leer, lleva más de MAX_WINDOW sin uso). */
    private static function isExpired(string $path, int $now): bool
    {
        $data = json_decode((string) @file_get_contents($path), true);
        if (is_array($data) && isset($data['reset'])) {
            return (int) $data['reset'] <= $now;
        }
        return @filemtime($path) < $now - self::MAX_WINDOW - 60;
    }
}
