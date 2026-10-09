<?php

/** Ejecuta `php point init` en un directorio temporal y pasa la ruta del proyecto y la salida. */
function withInitProject(\Closure $fn): void
{
    $root = dirname(__DIR__);
    $dir = sys_get_temp_dir() . '/point_init_' . bin2hex(random_bytes(4));
    $out = (string) shell_exec('php ' . escapeshellarg("$root/point") . ' init ' . escapeshellarg("$dir/app") . ' 2>&1');
    try {
        $fn("$dir/app", $out, $root);
    } finally {
        shell_exec('rm -rf ' . escapeshellarg($dir));
    }
}

test('parche 13: init termina sin warnings (no copia archivos inexistentes)', function () {
    withInitProject(function (string $app, string $out) {
        expect($out)->toContain('creado');
        expect($out)->not()->toContain('Warning');
    });
});

test('parche 13: init genera un JWT_SECRET hexadecimal limpio', function () {
    withInitProject(function (string $app) {
        preg_match('/^JWT_SECRET=(.*)$/m', file_get_contents("$app/.env"), $m);
        expect($m[1] ?? '')->toMatch('/^[0-9a-f]{64}$/');
    });
});

test('parche 13: init usa el composer.json de la plantilla', function () {
    withInitProject(function (string $app, string $out, string $root) {
        expect(file_get_contents("$app/composer.json"))->toBe(file_get_contents("$root/composer.json"));
        $composer = json_decode(file_get_contents("$app/composer.json"), true);
        expect($composer['require']['php'])->toBe('^8.4');
        expect($composer['autoload']['psr-4'])->toHaveKey('App\\Listeners\\');
        expect($composer['scripts'])->toHaveKey('stan');
        expect(is_file("$app/composer.lock"))->toBeTrue();
        expect(is_file("$app/phpstan.neon"))->toBeTrue();
        expect(is_dir("$app/listeners"))->toBeTrue();
    });
});

test('parche 13: init copia health, scheduler, public/ y los archivos de despliegue', function () {
    withInitProject(function (string $app) {
        foreach (['endpoints/health.php', 'config/schedule.php', 'public/index.php', 'public/.htaccess',
                  'Dockerfile', 'railway.json', 'docker/entrypoint.sh'] as $file) {
            expect(is_file("$app/$file"))->toBeTrue();
        }
        expect(is_executable("$app/scheduler"))->toBeTrue();
        expect(is_file("$app/.htaccess"))->toBeFalse();
        // health() y Scheduler vienen de plugins: quedan instalados y habilitados
        expect(is_file("$app/plugins/health/plugin.php"))->toBeTrue();
        expect(is_file("$app/plugins/scheduler/plugin.php"))->toBeTrue();
        expect(require "$app/config/plugins.php")->toEqual(['health', 'scheduler']);
    });
});

test('parche 18: init copia .dockerignore, que excluye .env pero no .env.example', function () {
    withInitProject(function (string $app) {
        expect(file_exists("$app/.dockerignore"))->toBeTrue();
        $lines = file("$app/.dockerignore", FILE_IGNORE_NEW_LINES);
        expect($lines)->toContain('.env');
        expect($lines)->toContain('.env.*');
        expect($lines)->toContain('!.env.example');
    });
});

test('parche 19: init copia el CI con Postgres desechable, migrate y tests', function () {
    withInitProject(function (string $app) {
        $ci = (string) @file_get_contents("$app/.github/workflows/ci.yml");
        expect($ci)->toContain('image: postgres:');
        expect($ci)->toContain('DB_DSN: pgsql:host=127.0.0.1');
        expect($ci)->toContain('run: php point migrate');
        expect($ci)->toContain('run: php point test');
        expect($ci)->toContain('actions/checkout@fbc6f3992d24b796d5a048ff273f7fcc4a7b6c09 # v5.1.0');
    });
});

test('parche 20: en un clon del proyecto (como en CI) existen todas las rutas de phpstan.neon', function () {
    withInitProject(function (string $app) {
        $clone = "$app-clone";
        shell_exec('cd ' . escapeshellarg($app) . ' && git init -q && git add -A'
            . ' && git -c user.name=t -c user.email=t@t commit -qm init'
            . ' && git clone -q . ' . escapeshellarg($clone) . ' 2>&1');
        preg_match_all('/^\s+- (\S+)$/m', explode('excludePaths', file_get_contents("$app/phpstan.neon"))[0], $m);
        $missing = array_values(array_filter($m[1], fn ($path) => !file_exists("$clone/$path")));
        expect($missing)->toBe([]);
    });
});

test('parche 25: init copia Rules.php y la config de middleware con public, authenticated y staff', function () {
    withInitProject(function (string $app) {
        expect(file_exists("$app/services/Rules.php"))->toBeTrue();
        expect(file_exists("$app/docs/REFERENCIA_ENDPOINT.md"))->toBeTrue();
        expect((string) file_get_contents("$app/.gitignore"))->toContain('requests.http');
        $groups = (require "$app/config/middleware.php")['groups'];
        foreach (['public', 'protected', 'authenticated', 'admin', 'staff'] as $group) {
            expect(array_key_exists($group, $groups))->toBeTrue();
        }
    });
});

test('init copia todos los archivos de core/ (un archivo nuevo del core no puede quedarse fuera)', function () {
    withInitProject(function (string $app, string $out, string $root) {
        $files = fn (string $base) => array_map(
            fn ($f) => substr($f, strlen($base) + 1),
            array_filter(iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$base/core", FilesystemIterator::SKIP_DOTS))), fn ($f) => str_ends_with((string) $f, '.php'))
        );
        $missing = array_diff(array_map('strval', $files($root)), array_map('strval', $files($app)));
        expect(array_values($missing))->toBe([]);
    });
});
