<?php

/** Arranca un servidor aislado con env propio (no el compartido de http(),
 *  que ya nació en modo development antes de que este archivo corra). */
function startIsolatedServer(array $env): array
{
    $root = dirname(__DIR__);
    $previous = [];
    foreach ($env as $key => $value) {
        $previous[$key] = getenv($key);
        putenv("$key=$value");
    }

    $sock = @stream_socket_server('tcp://127.0.0.1:0');
    $port = $sock ? (int) explode(':', stream_socket_get_name($sock, false))[1] : random_int(20000, 40000);
    if ($sock) fclose($sock);

    $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
    $process = proc_open("exec php -S 127.0.0.1:$port index.php", $descriptors, $pipes, $root);

    // El proceso hijo ya heredó el env al arrancar: se puede restaurar de una vez.
    foreach ($previous as $key => $value) {
        putenv($value === false ? $key : "$key=$value");
    }

    $maxWait = 50;
    while ($maxWait-- > 0) {
        usleep(100000);
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($fp) {
            fclose($fp);
            return ['process' => $process, 'port' => $port];
        }
    }

    proc_close($process);
    throw new RuntimeException('No se pudo iniciar el servidor aislado de prueba');
}

function stopIsolatedServer(array $server): void
{
    proc_terminate($server['process']);
    proc_close($server['process']);
}

function httpIsolated(int $port, string $path, array $headers = []): array
{
    $headerLines = [];
    foreach ($headers as $key => $value) {
        $headerLines[] = "$key: $value";
    }

    $context = stream_context_create([
        'http' => ['method' => 'GET', 'header' => implode("\r\n", $headerLines), 'ignore_errors' => true, 'timeout' => 5],
    ]);

    @file_get_contents("http://127.0.0.1:$port$path", false, $context);

    $responseHeaders = [];
    foreach ($http_response_header ?? [] as $h) {
        $parts = explode(':', $h, 2);
        if (count($parts) === 2) {
            $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }
    return $responseHeaders;
}

test('cabeceras de seguridad adicionales presentes en toda respuesta', function () {
    $res = http('GET', '/health');
    expect($res->headers['permissions-policy'] ?? null)->toContain('geolocation=()');
    expect($res->headers['cross-origin-opener-policy'] ?? null)->toBe('same-origin');
    // cross-origin (no same-origin): la API sirve peticiones cross-origin a propósito
    expect($res->headers['cross-origin-resource-policy'] ?? null)->toBe('cross-origin');
});

test('CORS en development: sin CORS_ORIGINS configurado, usa * por defecto', function () {
    $res = http('GET', '/health', [], ['Origin' => 'https://example.com']);
    expect($res->headers['access-control-allow-origin'] ?? null)->toBe('*');
});

test('CORS fuera de development: sin CORS_ORIGINS configurado, no hay Access-Control-Allow-Origin', function () {
    $server = startIsolatedServer(['APP_ENV' => 'production', 'JWT_SECRET' => str_repeat('x', 32)]);
    try {
        $headers = httpIsolated($server['port'], '/health', ['Origin' => 'https://example.com']);
        expect($headers)->not()->toHaveKey('access-control-allow-origin');
    } finally {
        stopIsolatedServer($server);
    }
});

test('CORS fuera de development: con CORS_ORIGINS configurado, sí lo respeta', function () {
    $server = startIsolatedServer([
        'APP_ENV' => 'production',
        'JWT_SECRET' => str_repeat('x', 32),
        'CORS_ORIGINS' => 'https://example.com',
    ]);
    try {
        $headers = httpIsolated($server['port'], '/health', ['Origin' => 'https://example.com']);
        expect($headers['access-control-allow-origin'] ?? null)->toBe('https://example.com');
    } finally {
        stopIsolatedServer($server);
    }
});
