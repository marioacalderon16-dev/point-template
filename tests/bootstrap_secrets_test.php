<?php

/** Corre bootstrap.php en un subproceso aislado con env controlado (no comparte
 *  el servidor de tests: necesitamos variar APP_ENV/JWT_SECRET por caso). */
function bootWith(array $env): string
{
    $root = dirname(__DIR__);
    $code = "require 'vendor/autoload.php'; ";
    foreach ($env as $key => $value) {
        $code .= "putenv(" . var_export("$key=$value", true) . "); ";
    }
    $code .= "define('POINT_NO_ROUTES', true); require 'bootstrap.php'; echo 'BOOTSTRAP_OK';";

    return shell_exec('cd ' . escapeshellarg($root) . ' && php -d display_errors=1 -r ' . escapeshellarg($code) . ' 2>&1');
}

test('bootstrap fail-fast: JWT_SECRET inválido detiene el arranque en producción', function () {
    $output = bootWith(['APP_ENV' => 'production', 'JWT_SECRET' => '']);
    expect($output)->not()->toContain('BOOTSTRAP_OK');
    expect($output)->toContain('JWT_SECRET');
});

test('bootstrap: en development un JWT_SECRET inválido no bloquea el arranque', function () {
    $output = bootWith(['APP_ENV' => 'development', 'JWT_SECRET' => '']);
    expect($output)->toContain('BOOTSTRAP_OK');
});

test('bootstrap: en producción con JWT_SECRET válido arranca normalmente', function () {
    $output = bootWith(['APP_ENV' => 'production', 'JWT_SECRET' => str_repeat('x', 32)]);
    expect($output)->toContain('BOOTSTRAP_OK');
});
