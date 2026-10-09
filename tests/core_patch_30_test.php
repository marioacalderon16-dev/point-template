<?php

use Core\Endpoint;

/** Clave única por ejecución: una entrada de caché de corridas anteriores no puede dar un HIT. */
function p30Q(): string
{
    static $run;
    return $run ??= bin2hex(random_bytes(6));
}

/** $variant cambia el campo declarado q (los campos no declarados en expects no llegan al handler ni a la clave). */
function p30Get(string $path, string $variant = '', array $headers = []): object
{
    return http('GET', $path . '?' . http_build_query(['q' => p30Q() . $variant]), [], $headers);
}

test('parche 30: cache() guarda la respuesta GET y la reutiliza (X-Cache MISS y luego HIT)', function () {
    $first = p30Get('/_test/p30/cached', 'a');
    $second = p30Get('/_test/p30/cached', 'a');
    expect($first->headers['x-cache'] ?? null)->toBe('MISS');
    expect($second->headers['x-cache'] ?? null)->toBe('HIT');
    expect($second->body['data']['n'])->toBe($first->body['data']['n']);
});

test('parche 30: la clave incluye los campos validados de la query', function () {
    $a = p30Get('/_test/p30/cached', 'b');
    $b = p30Get('/_test/p30/cached', 'c');
    expect($b->body['data']['n'])->not()->toBe($a->body['data']['n']);
});

test('parche 30: nunca mezcla usuarios', function () {
    $u1 = Core\Auth::issue(['sub' => 1]);
    $u2 = Core\Auth::issue(['sub' => 2]);
    p30Get('/_test/p30/cached-user', '', ['Authorization' => "Bearer $u1"]);
    $r2 = p30Get('/_test/p30/cached-user', '', ['Authorization' => "Bearer $u2"]);
    expect($r2->body['data']['user'])->toBe(2);
    expect($r2->headers['x-cache'] ?? null)->toBe('MISS');
    // Otro token del mismo usuario (otro iat/exp) sí reutiliza su caché
    $u1b = Core\Auth::issue(['sub' => 1], 120);
    expect(p30Get('/_test/p30/cached-user', '', ['Authorization' => "Bearer $u1b"])->headers['x-cache'] ?? null)->toBe('HIT');
});

test('parche 30: no guarda respuestas de error ni peticiones que no son GET', function () {
    p30Get('/_test/p30/cached-error');
    $again = p30Get('/_test/p30/cached-error');
    expect($again->status)->toBe(404);
    expect($again->headers['x-cache'] ?? null)->toBeNull();

    $post = http('POST', '/_test/p30/cached', ['q' => p30Q()]);
    expect($post->status)->toBe(200);
    expect($post->headers['x-cache'] ?? null)->toBeNull();
});

test('parche 30: cache() exige al menos 1 segundo', function () {
    $thrown = false;
    try {
        Endpoint::from(__FILE__)->cache(0);
    } catch (InvalidArgumentException) {
        $thrown = true;
    }
    expect($thrown)->toBeTrue();
});

test('parche 30: test --smoke detecta rutas con 5xx, prueba rutas con rol y omite escrituras salvo con =all', function () {
    $root = dirname(__DIR__);
    $dir = sys_get_temp_dir() . '/point_smoke_' . bin2hex(random_bytes(4));
    $app = "$dir/app";
    shell_exec('php ' . escapeshellarg("$root/point") . ' init ' . escapeshellarg($app) . ' 2>&1');
    symlink("$root/vendor", "$app/vendor");
    file_put_contents("$app/.env", "APP_ENV=development\nDB_DSN=sqlite:$app/dev.sqlite\nJWT_SECRET=" . str_repeat('a', 64) . "\n");
    $endpoint = fn (string $route, string $extra, string $body) => "<?php\nuse Core\\Endpoint;\n"
        . "Endpoint::from(__FILE__)->at('{$route}'){$extra}->handle(fn (\$in) => {$body});\n";
    file_put_contents("$app/endpoints/boom.php", $endpoint('GET /boom', "->group('public')", "throw new RuntimeException('roto')"));
    file_put_contents("$app/endpoints/write.php", $endpoint('POST /write', "->group('public')->expects(['name' => 'required|string'])", "throw new RuntimeException('roto')"));
    file_put_contents("$app/endpoints/staff.php", $endpoint('GET /staff-only', "->group('staff')", "['status' => 200, 'data' => 'ok']"));

    $run = function (string $arg) use ($app): array {
        exec('cd ' . escapeshellarg($app) . ' && env -u DB_DSN -u DB_USER -u DB_PASS -u JWT_SECRET -u APP_ENV php point test ' . $arg . ' 2>&1', $out, $code);
        return [implode("\n", $out), $code];
    };
    try {
        [$out, $code] = $run('--smoke');
        expect($code)->toBe(1);
        expect($out)->toMatch('#FAIL.*GET\s+/boom\s+500#');
        expect($out)->toMatch('#OK.*GET\s+/staff-only\s+200#');
        expect($out)->toContain('1 de escritura omitidas');
        expect($out)->not()->toContain('/write');

        [$outAll] = $run('--smoke=all');
        expect($outAll)->toMatch('#FAIL.*POST\s+/write\s+500#');
    } finally {
        shell_exec('rm -rf ' . escapeshellarg($dir));
    }
});
