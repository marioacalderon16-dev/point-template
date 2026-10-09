<?php

/** Arranca una copia de bootstrap.php en un directorio temporal con su propio `.env` (el del
 *  proyecto no se toca) y devuelve el $_ENV resultante para las claves pedidas. */
function bootWithDotenv(array $processEnv, string $dotenv, array $keys): array
{
    $root = dirname(__DIR__);
    $dir = sys_get_temp_dir() . '/point_env_' . bin2hex(random_bytes(4));
    mkdir($dir);
    copy("$root/bootstrap.php", "$dir/bootstrap.php");
    foreach (['core', 'config', 'plugins', 'vendor'] as $link) {
        if (file_exists("$root/$link")) symlink("$root/$link", "$dir/$link");
    }
    file_put_contents("$dir/.env", $dotenv);

    $code = "require 'vendor/autoload.php'; ";
    foreach ($processEnv as $key => $value) {
        $code .= 'putenv(' . var_export("$key=$value", true) . '); ';
    }
    $code .= "define('POINT_NO_ROUTES', true); require 'bootstrap.php'; "
        . 'echo json_encode(array_intersect_key($_ENV, array_flip(' . var_export($keys, true) . ')));';

    try {
        $out = shell_exec('cd ' . escapeshellarg($dir) . ' && php -r ' . escapeshellarg($code) . ' 2>/dev/null');
        return json_decode((string) $out, true) ?? [];
    } finally {
        shell_exec('rm -rf ' . escapeshellarg($dir));
    }
}

test('parche 12: las variables del proceso tienen prioridad sobre .env', function () {
    $env = bootWithDotenv(['POINT_PROBE_A' => 'proceso'], "POINT_PROBE_A=archivo\n", ['POINT_PROBE_A']);
    expect($env['POINT_PROBE_A'] ?? null)->toBe('proceso');
});

test('parche 12: .env completa las variables que el proceso no define', function () {
    $env = bootWithDotenv([], "POINT_PROBE_B=archivo\n", ['POINT_PROBE_B']);
    expect($env['POINT_PROBE_B'] ?? null)->toBe('archivo');
});
