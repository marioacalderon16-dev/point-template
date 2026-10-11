<?php

/** Ejecuta `point make:…` en una carpeta temporal con el entorno indicado; devuelve [salida, código]. */
function makeOpenRun(string $dir, string $args, string $env = ''): array
{
    exec('cd ' . escapeshellarg($dir) . " && env -u POINT_EDITOR -u VISUAL -u EDITOR $env php "
        . escapeshellarg(dirname(__DIR__) . '/point') . " $args 2>&1", $out, $code);
    return [implode("\n", $out), $code];
}

function makeOpenDir(): string
{
    $dir = sys_get_temp_dir() . '/point_open_' . bin2hex(random_bytes(4));
    mkdir($dir);
    return $dir;
}

test('make:* --open abre el archivo creado con POINT_EDITOR', function () {
    $dir = makeOpenDir();
    try {
        // "echo" como editor: imprime la ruta que recibiría el editor real
        [$out, $code] = makeOpenRun($dir, 'make:endpoint hola --public --open', 'POINT_EDITOR=echo');
        expect($code)->toBe(0);
        expect($out)->toContain('Abriendo con echo: ');
        expect($out)->toMatch('#\n\S*endpoints/hola\.php$#');
        expect(file_exists("$dir/endpoints/hola.php"))->toBeTrue();

        [$outAction] = makeOpenRun($dir, 'make:action CreateUser --open', 'POINT_EDITOR=echo');
        expect($outAction)->toContain("Abriendo con echo: services/Actions/CreateUser.php\nservices/Actions/CreateUser.php");
    } finally {
        shell_exec('rm -rf ' . escapeshellarg($dir));
    }
});

test('make:* --open toma POINT_EDITOR de .env y, sin --open, no abre nada', function () {
    $dir = makeOpenDir();
    try {
        file_put_contents("$dir/.env", "APP_ENV=development\nPOINT_EDITOR=\"echo\"\n");
        [$out] = makeOpenRun($dir, 'make:test usuarios --open');
        expect($out)->toContain('Abriendo con echo: tests/usuarios_test.php');

        [$quiet] = makeOpenRun($dir, 'make:test pedidos');
        expect($quiet)->not()->toContain('Abriendo');
    } finally {
        shell_exec('rm -rf ' . escapeshellarg($dir));
    }
});

test('make:* --open avisa si el editor no se puede abrir', function () {
    $dir = makeOpenDir();
    try {
        [$out, $code] = makeOpenRun($dir, 'make:endpoint otra --open', 'POINT_EDITOR=false');
        expect($code)->toBe(0);
        expect(file_exists("$dir/endpoints/otra.php"))->toBeTrue();
        expect($out)->toContain("No se pudo abrir con 'false'");
    } finally {
        shell_exec('rm -rf ' . escapeshellarg($dir));
    }
});
