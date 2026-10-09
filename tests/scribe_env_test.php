<?php

/** Ejecuta una copia de `scribe` en un directorio temporal (con su propio `.env` opcional) y
 *  devuelve [salida, código, directorio]. El directorio se borra al terminar `$check`. */
function runScribe(array $args, array $processEnv, ?string $dotenv, callable $check): void
{
    $root = dirname(__DIR__);
    $dir = sys_get_temp_dir() . '/point_scribe_' . bin2hex(random_bytes(4));
    mkdir("$dir/database/migrations", 0777, true);
    copy("$root/scribe", "$dir/scribe");
    foreach (['core', 'vendor'] as $link) {
        symlink("$root/$link", "$dir/$link");
    }
    if ($dotenv !== null) file_put_contents("$dir/.env", $dotenv);
    file_put_contents(
        "$dir/database/migrations/2026_01_01_000000_create_probes.table.php",
        "<?php\nuse Core\\Scribe\\Table;\n\nreturn Table::create('probes')\n    ->id()\n    ->str('name');\n"
    );

    $env = '';
    foreach ($processEnv as $key => $value) {
        $env .= escapeshellarg("$key=" . str_replace('{dir}', $dir, $value)) . ' ';
    }
    $cmd = 'cd ' . escapeshellarg($dir) . " && env $env php scribe "
        . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';

    try {
        exec($cmd, $out, $code);
        $check(implode("\n", $out), $code, $dir);
    } finally {
        shell_exec('rm -rf ' . escapeshellarg($dir));
    }
}

test('parche 15: scribe migrate usa DB_DSN del entorno del proceso (sin database/config.php ni .env)', function () {
    runScribe(['migrate'], ['DB_DSN' => 'sqlite:{dir}/db.sqlite'], null, function ($out, $code, $dir) {
        expect($code)->toBe(0);
        expect($out)->toContain('1 migraciones aplicadas');
        $tables = (new PDO("sqlite:$dir/db.sqlite"))
            ->query("SELECT name FROM sqlite_master WHERE name = 'probes'")->fetchAll();
        expect(count($tables))->toBe(1);
    });
});

test('parche 15: en scribe el entorno del proceso tiene prioridad sobre .env', function () {
    runScribe(
        ['migrate'],
        ['DB_DSN' => 'sqlite:{dir}/proceso.sqlite'],
        "DB_DSN=sqlite:/nonexistent/dir/archivo.sqlite\n",
        function ($out, $code, $dir) {
            expect($code)->toBe(0);
            expect(file_exists("$dir/proceso.sqlite"))->toBeTrue();
        }
    );
});

test('parche 15: scribe sin DB_DSN falla con un mensaje claro', function () {
    runScribe(['migrate'], ['DB_DSN' => ''], null, function ($out, $code) {
        expect($code)->toBe(1);
        expect($out)->toContain('DB_DSN no está definido');
    });
});
