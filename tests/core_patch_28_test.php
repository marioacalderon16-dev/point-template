<?php

use Core\Endpoint;

/** Despacha una ruta inexistente con Endpoint::run() en un subproceso y devuelve el JSON tal cual sale. */
function p28Raw404(string $appEnv): string
{
    $code = "require 'vendor/autoload.php'; \$_ENV = getenv(); \$_SERVER['REQUEST_METHOD'] = 'GET'; "
        . "\$_SERVER['REQUEST_URI'] = '/no-existe'; Core\\Endpoint::run();";
    return (string) shell_exec('cd ' . escapeshellarg(dirname(__DIR__)) . ' && env APP_ENV=' . escapeshellarg($appEnv)
        . ' php -r ' . escapeshellarg($code) . ' 2>&1');
}

test('parche 28: at() rechaza formatos inválidos con un mensaje claro', function () {
    foreach (['GET', '/users', 'GET users', 'FETCH /users', 'GET|BORRAR /users', ''] as $bad) {
        $thrown = false;
        try {
            Endpoint::from(__FILE__)->at($bad);
        } catch (InvalidArgumentException $e) {
            $thrown = str_contains($e->getMessage(), 'Endpoint::at(');
        }
        expect($thrown)->toBeTrue();
    }
});

test('parche 28: at() tolera espacios de más', function () {
    expect(http('GET', '/_test/p28/spaces')->status)->toBe(200);
});

test('parche 28: método no permitido da 405 con Allow, ruta inexistente sigue dando 404', function () {
    $res = http('DELETE', '/health');
    expect($res->status)->toBe(405);
    expect($res->headers['allow'] ?? '')->toContain('GET');
    expect(http('GET', '/_test/p28/no-existe')->status)->toBe(404);
});

test('parche 28: expects() se acumula entre llamadas', function () {
    expect(http('POST', '/_test/p28/merge', ['b' => 'x'])->status)->toBe(422);
    $res = http('POST', '/_test/p28/merge', ['a' => 'x', 'b' => 'y']);
    expect($res->status)->toBe(200);
    expect($res->body['data']['keys'])->toBe(['a', 'b']);
});

test('parche 28: onError que devuelve texto responde un JSON con message', function () {
    $res = http('GET', '/_test/p28/onerror');
    expect($res->status)->toBe(500);
    expect($res->body['message'])->toBe('Servicio no disponible');
});

test('parche 28: JSON con sangría solo en development', function () {
    expect(p28Raw404('production'))->toBe('{"error":"Ruta no encontrada"}');
    expect(p28Raw404('development'))->toContain("\n");
});
