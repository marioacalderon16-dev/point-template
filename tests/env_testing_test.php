<?php

/** Proyecto de `init` con una migración y un test que consulta /health; ejecuta su `php point test`
 *  sin las DB_* del proceso (en CI vienen definidas) y devuelve [salida, directorio]. */
function runProjectTests(?string $envTesting, callable $check): void
{
    $root = dirname(__DIR__);
    $dir = sys_get_temp_dir() . '/point_envtesting_' . bin2hex(random_bytes(4));
    $app = "$dir/app";
    shell_exec('php ' . escapeshellarg("$root/point") . ' init ' . escapeshellarg($app) . ' 2>&1');
    symlink("$root/vendor", "$app/vendor");
    file_put_contents("$app/.env", "DB_DSN=sqlite:$app/dev.sqlite\nJWT_SECRET=" . str_repeat('a', 64) . "\n");
    if ($envTesting !== null) file_put_contents("$app/.env.testing", str_replace('{app}', $app, $envTesting));
    file_put_contents(
        "$app/database/migrations/2026_01_01_000000_create_probes.table.php",
        "<?php\nuse Core\\Scribe\\Table;\n\nreturn Table::create('probes')->id()->str('name');\n"
    );
    file_put_contents("$app/tests/probe_test.php", "<?php\ntest('health', function () {\n"
        . "    expect(http('GET', '/health')->status)->toBe(200);\n});\n");

    try {
        $out = (string) shell_exec('cd ' . escapeshellarg($app)
            . ' && env -u DB_DSN -u DB_USER -u DB_PASS -u JWT_SECRET php point test 2>&1');
        $check($out, $app);
    } finally {
        shell_exec('rm -rf ' . escapeshellarg($dir));
    }
}

function sqliteTables(string $file): array
{
    if (!file_exists($file)) return [];
    return (new PDO("sqlite:$file"))->query("SELECT name FROM sqlite_master WHERE type = 'table'")
        ->fetchAll(PDO::FETCH_COLUMN);
}

test('parche 21: con .env.testing, point test migra y usa la base de tests, no la de .env', function () {
    runProjectTests("DB_DSN=sqlite:{app}/test.sqlite\n", function (string $out, string $app) {
        expect($out)->toContain('1 migraciones aplicadas');
        expect($out)->toContain('1 tests, all passed');
        expect($out)->not()->toContain('Aviso');
        expect(sqliteTables("$app/test.sqlite"))->toContain('probes');
        expect(sqliteTables("$app/dev.sqlite"))->not()->toContain('probes');
    });
});

test('parche 21: sin .env.testing, point test avisa de que usa la base de desarrollo', function () {
    runProjectTests(null, function (string $out) {
        expect($out)->toContain('Aviso: los tests usan la base de .env');
        expect($out)->not()->toContain('migraciones aplicadas');
    });
});
