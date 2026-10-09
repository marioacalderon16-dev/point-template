<?php

/** Ejecuta `point make:test` en un directorio temporal y devuelve [salida, código, directorio]. */
function makeTestIn(string $dir, string $name): array
{
    exec('cd ' . escapeshellarg($dir) . ' && php ' . escapeshellarg(dirname(__DIR__) . '/point')
        . ' make:test ' . escapeshellarg($name) . ' 2>&1', $out, $code);
    return [implode("\n", $out), $code];
}

test('parche 16: make:test crea tests/<nombre>_test.php con un test de ejemplo válido', function () {
    $dir = sys_get_temp_dir() . '/point_maketest_' . bin2hex(random_bytes(4));
    mkdir($dir);
    try {
        [$out, $code] = makeTestIn($dir, 'Users_test');
        expect($code)->toBe(0);
        expect($out)->toContain('tests/users_test.php');
        $file = "$dir/tests/users_test.php";
        expect(file_exists($file))->toBeTrue();
        exec('php -l ' . escapeshellarg($file), $lint, $lintCode);
        expect($lintCode)->toBe(0);
        expect(file_get_contents($file))->toContain("http('GET', '/health')");

        [$out, $code] = makeTestIn($dir, 'users');
        expect($code)->toBe(1);
        expect($out)->toContain('Ya existe');
    } finally {
        shell_exec('rm -rf ' . escapeshellarg($dir));
    }
});
