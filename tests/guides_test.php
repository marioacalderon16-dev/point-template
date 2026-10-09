<?php

require_once __DIR__ . '/support/postgres.php';

/**
 * Las guías de docs/guias son la única fuente: este test crea un proyecto con `point init`, escribe los
 * bloques ```php file=RUTA de cada guía en orden (un bloque posterior reemplaza al anterior, como hace el
 * lector) y comprueba contra un Postgres nuevo que cada paso responde lo que dice el texto.
 */
function guideBlocks(string $guide): array
{
    $md = (string) file_get_contents(dirname(__DIR__) . "/docs/guias/$guide");
    preg_match_all('/^```php file=(\S+)\n(.*?)^```/ms', $md, $m, PREG_SET_ORDER);
    return array_map(fn ($b) => ['path' => $b[1], 'code' => $b[2]], $m);
}

function guideApply(string $app, string $guide): void
{
    foreach (guideBlocks($guide) as $b) {
        $file = "$app/{$b['path']}";
        @mkdir(dirname($file), 0755, true);
        file_put_contents($file, $b['code']);
    }
}

/**
 * vendor/ de la plantilla (sin composer install), pero con las clases del proyecto por delante: el
 * autoloader de Composer apunta a las carpetas de la plantilla, no a las de este proyecto.
 */
function guideAutoload(string $app, string $root): void
{
    mkdir("$app/vendor");
    $map = ['Core\\' => 'core', 'Endpoints\\' => 'endpoints', 'App\\Services\\' => 'services',
        'App\\Middlewares\\' => 'middlewares', 'App\\Jobs\\' => 'jobs', 'App\\Listeners\\' => 'listeners'];
    $lines = ["<?php", '$loader = require ' . var_export("$root/vendor/autoload.php", true) . ';'];
    foreach ($map as $ns => $dir) {
        $lines[] = '$loader->addPsr4(' . var_export($ns, true) . ', __DIR__ . ' . var_export("/../$dir/", true) . ', true);';
    }
    $lines[] = 'return $loader;';
    file_put_contents("$app/vendor/autoload.php", implode("\n", $lines) . "\n");
}

/** Ejecuta `php point …` en el proyecto con la base de la guía (las variables del proceso mandan sobre .env). */
function guidePoint(string $app, array $db, string $args): array
{
    $env = 'env -u POINT_TESTING APP_ENV=development DB_DSN=' . escapeshellarg($db[0])
        . ' DB_USER=' . escapeshellarg($db[1]) . ' DB_PASS=' . escapeshellarg($db[2]);
    exec('cd ' . escapeshellarg($app) . " && $env php point $args < /dev/null 2>&1", $out, $code);
    return [implode("\n", $out), $code];
}

function guideData(array $result): mixed
{
    return json_decode($result[0], true);
}

test('guías: cada bloque de código indica un archivo dentro del proyecto', function () {
    $guides = array_map('basename', glob(dirname(__DIR__) . '/docs/guias/*.md'));
    expect(count($guides))->toBeGreaterThan(2);
    foreach ($guides as $g) {
        $blocks = guideBlocks($g);
        expect(count($blocks))->toBeGreaterThan(0);
        foreach ($blocks as $b) {
            expect(str_contains($b['path'], '..') || str_starts_with($b['path'], '/'))->toBeFalse();
        }
    }
});

test('guías 1-3: el proyecto Tareas funciona siguiendo los pasos en orden', function () {
    $ran = withFreshPostgres(function (string $dsn, string $user, string $pass) {
        $db = [$dsn, $user, $pass];
        $root = dirname(__DIR__);
        $dir = sys_get_temp_dir() . '/point_guides_' . bin2hex(random_bytes(4));
        $app = "$dir/tareas";
        shell_exec('php ' . escapeshellarg("$root/point") . ' init ' . escapeshellarg($app) . ' 2>&1');
        guideAutoload($app, $root);
        try {
            // Guía 1: GET /hola
            guideApply($app, '01-hola.md');
            expect(guideData(guidePoint($app, $db, 'call hola --data')))->toBe(['saludo' => 'Hola, mundo']);
            expect(guideData(guidePoint($app, $db, 'call hola nombre=Ana --data')))->toBe(['saludo' => 'Hola, Ana']);
            expect(guidePoint($app, $db, 'call hola nombre=' . str_repeat('a', 60))[1])->toBe(1);

            // Guía 2: projects con migración, seed, listado paginado, detalle y alta
            guideApply($app, '02-base-de-datos.md');
            expect(guidePoint($app, $db, 'migrate')[0])->toContain('1 migraciones aplicadas');
            expect(guidePoint($app, $db, 'seed')[1])->toBe(0);
            expect(count(guideData(guidePoint($app, $db, 'call projects.list --data'))))->toBe(3);
            expect(count(guideData(guidePoint($app, $db, 'call projects.list limit=2 page=2 --data'))))->toBe(1);
            expect(guideData(guidePoint($app, $db, 'call projects.show id=2 --data'))['name'])->toBe('App móvil');
            [$out404, $code404] = guidePoint($app, $db, 'call projects.show id=999');
            expect($code404)->toBe(1);
            expect($out404)->toContain('Proyecto no encontrado');
            expect(guidePoint($app, $db, 'call projects.show id=abc')[1])->toBe(1);
            expect(guideData(guidePoint($app, $db, 'call projects.create name=Lanzamiento --data'))['name'])->toBe('Lanzamiento');
            expect(guidePoint($app, $db, 'call projects.create name=X')[1])->toBe(1);

            // Guía 3: usuarios, login, /me y proyectos con dueño
            guideApply($app, '03-usuarios-y-login.md');
            expect(guidePoint($app, $db, 'migrate')[0])->toContain('2 migraciones aplicadas');
            $created = guidePoint($app, $db, 'call users.create name=Marta email=Marta@Example.com password=secreto123 --data');
            expect($created[1])->toBe(0);
            $user = guideData($created);
            expect($user['email'])->toBe('marta@example.com');
            expect(array_key_exists('password', $user))->toBeFalse();
            expect(guidePoint($app, $db, 'call users.create name=Marta email=marta@example.com password=secreto123')[1])->toBe(1);

            $stored = (new PDO($dsn, $db[1], $db[2]))->query('SELECT password FROM users')->fetchColumn();
            expect(str_starts_with((string) $stored, '$2y$'))->toBeTrue();

            $login = guideData(guidePoint($app, $db, 'call login email=marta@example.com password=secreto123 --data'));
            expect(strlen($login['token']))->toBeGreaterThan(50);
            expect(guidePoint($app, $db, 'call login email=marta@example.com password=incorrecta')[1])->toBe(1);
            expect(guidePoint($app, $db, 'call login email=nadie@example.com password=secreto123')[1])->toBe(1);

            expect(guideData(guidePoint($app, $db, "call me --as={$user['id']} --data"))['email'])->toBe('marta@example.com');
            expect(guidePoint($app, $db, 'call me')[1])->toBe(1);

            expect(guidePoint($app, $db, 'call projects.create name=Intranet')[1])->toBe(1);
            $project = guideData(guidePoint($app, $db, "call projects.create name=Intranet --as={$user['id']} --data"));
            expect($project['owner_id'])->toBe($user['id']);
            expect($project['description'])->toBeNull();
        } finally {
            shell_exec('rm -rf ' . escapeshellarg($dir));
        }
    });
    if (!$ran) {
        fwrite(STDERR, "  (guías: sin Postgres disponible, no se ha comprobado nada)\n");
    }
    expect(true)->toBeTrue();
});

test('guías: los enlaces entre guías y al índice apuntan a archivos que existen', function () {
    $docs = dirname(__DIR__) . '/docs';
    $broken = [];
    foreach (array_merge(["$docs/GUIA_INICIO.md"], glob("$docs/guias/*.md")) as $file) {
        preg_match_all('/\]\(([^)#\s]+\.md)(?:#[^)]*)?\)/', (string) file_get_contents($file), $m);
        foreach ($m[1] as $link) {
            if (!str_starts_with($link, 'http') && !is_file(dirname($file) . "/$link")) {
                $broken[] = basename($file) . " → $link";
            }
        }
    }
    expect($broken)->toBe([]);
});
