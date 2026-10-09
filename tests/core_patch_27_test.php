<?php

use Core\Auth;
use Core\UploadedFile;
use Core\Validator;

/** Ejecuta código PHP en un subproceso desde la raíz del proyecto con el entorno indicado ('' = sin definir). */
function p27Run(string $code, array $env = []): string
{
    $prefix = '';
    foreach ($env as $key => $value) {
        $prefix .= $value === '' ? 'env -u ' . escapeshellarg($key) . ' ' : escapeshellarg("$key=$value") . ' ';
    }
    $cmd = 'cd ' . escapeshellarg(dirname(__DIR__)) . ' && ' . ($prefix ? "env $prefix" : '')
        . 'php -r ' . escapeshellarg("require 'vendor/autoload.php'; \$_ENV = getenv(); $code") . ' 2>&1';
    return (string) shell_exec($cmd);
}

test('parche 27: las claves _user_id y _user del cliente se descartan (query y body)', function () {
    $res = http('POST', '/_test/p27/echo?_user_id=1&_user[role]=admin&q=1', ['_user_id' => 2, '_x' => 3, 'name' => 'a']);
    expect($res->status)->toBe(200);
    expect($res->body['data']['keys'])->toBe(['name', 'q']);
});

test('parche 27: _user_id en la query no salta el rate limit', function () {
    $codes = [];
    foreach ([1, 2, 3] as $i) {
        $codes[] = http('POST', "/_test/p27/limited?_user_id=r$i", ['x' => 'a'])->status;
    }
    expect($codes)->toBe([200, 200, 429]);
});

test('parche 27: un _user falso no da roles en una ruta pública', function () {
    $res = http('GET', '/_test/p27/admin-guard?_user[role]=admin&_user[sub]=1');
    expect($res->status)->toBe(500);
    expect(json_encode($res->body))->not()->toContain('no debería llegar');
});

test('parche 27: sin APP_ENV se asume producción', function () {
    expect(p27Run("var_export(Core\\ErrorHandler::isDevelopment());", ['APP_ENV' => '']))->toBe('false');
    expect(p27Run("var_export(Core\\ErrorHandler::isDevelopment());", ['APP_ENV' => 'development']))->toBe('true');
});

test('parche 27: /health fuera de development solo muestra el estado de cada check', function () {
    $out = p27Run(
        "define('POINT_NO_ROUTES', true); require 'bootstrap.php'; echo json_encode(health());",
        ['APP_ENV' => 'production', 'JWT_SECRET' => str_repeat('x', 32), 'DB_DSN' => 'pgsql:host=127.0.0.1;port=1;dbname=nada']
    );
    $data = json_decode($out, true);
    expect($data['checks']['database'])->toBe(['status' => 'error']);
    expect(array_keys($data['checks']['disk']))->toBe(['status']);
});

test('parche 27: los avisos silenciados con @ no provocan 500', function () {
    expect(p27Run("Core\\ErrorHandler::register(); @file_get_contents('/no/existe'); echo 'OK';"))->toBe('OK');
});

test('parche 27: string, unique y exists con un array dan 422 en vez de 500', function () {
    foreach (['string', 'unique:users,email', 'exists:users,id'] as $rule) {
        $v = new Validator(['f' => ['x']], ['f' => "required|$rule"]);
        expect($v->passes())->toBeFalse();
    }
});

test('parche 27: mime comprueba el contenido real, no solo la extensión', function () {
    $fake = tempnam(sys_get_temp_dir(), 'p27');
    file_put_contents($fake, "<?php echo 'hola';");
    $png = tempnam(sys_get_temp_dir(), 'p27');
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
    try {
        $file = fn (string $tmp, string $name) => new UploadedFile(['tmp_name' => $tmp, 'name' => $name, 'type' => 'image/png', 'size' => 10, 'error' => 0]);
        expect((new Validator(['f' => $file($fake, 'foto.png')], ['f' => 'mime:png']))->passes())->toBeFalse();
        expect((new Validator(['f' => $file($png, 'foto.png')], ['f' => 'mime:png']))->passes())->toBeTrue();
        expect((new Validator(['f' => $file($png, 'foto.gif')], ['f' => 'mime:png']))->passes())->toBeFalse();
    } finally {
        unlink($fake);
        unlink($png);
    }
});

test('parche 27: store() no permite salir de storage/uploads', function () {
    $file = new UploadedFile(['tmp_name' => '/no/existe', 'name' => 'a.txt', 'type' => 'text/plain', 'size' => 1, 'error' => 0]);
    foreach ([['../fuera', null], ['a/../../fuera', null], ['ok', '..']] as [$dir, $name]) {
        $thrown = false;
        try {
            $file->store($dir, $name);
        } catch (InvalidArgumentException) {
            $thrown = true;
        }
        expect($thrown)->toBeTrue();
    }
});

test('parche 27: verify exige el iss/aud propios y los claims no pueden pisarlos', function () {
    $claims = Auth::verify(Auth::issue(['sub' => 1, 'iss' => 'otro', 'aud' => 'otro']));
    expect($claims['iss'])->toBe('point');
    expect($claims['aud'])->toBe('point-api');

    $secret = $_ENV['JWT_SECRET'];
    $foreign = Firebase\JWT\JWT::encode(['sub' => 1, 'exp' => time() + 60], $secret, 'HS256');
    expect(Auth::verify($foreign))->toBeNull();
    $supabase = Firebase\JWT\JWT::encode(['sub' => 1, 'exp' => time() + 60, 'iss' => 'supabase', 'aud' => 'authenticated'], $secret, 'HS256');
    expect(Auth::verify($supabase))->toBeNull();
});

test('parche 27: routes avisa de rutas de escritura sin expects', function () {
    $out = (string) shell_exec('cd ' . escapeshellarg(dirname(__DIR__)) . ' && POINT_TESTING=1 php point routes 2>&1');
    expect($out)->toContain('NO-EXPECTS');
    expect($out)->toContain('- POST /_test/p27/echo');
    expect($out)->not()->toContain('- POST /_test/rules');
});
