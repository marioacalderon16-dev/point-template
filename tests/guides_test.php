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
    preg_match_all('/^```php file=(\S+)(?: method=(\w+))?\n(.*?)^```/ms', $md, $m, PREG_SET_ORDER);
    return array_map(fn ($b) => ['path' => $b[1], 'method' => $b[2] ?: null, 'code' => $b[3]], $m);
}

function guideApply(string $app, string $guide): void
{
    foreach (guideBlocks($guide) as $b) {
        $file = "$app/{$b['path']}";
        @mkdir(dirname($file), 0755, true);
        if ($b['method'] !== null) {
            // Bloque "method=…": el lector reemplaza solo ese método del archivo
            $src = (string) file_get_contents($file);
            $start = strpos($src, "    public static function {$b['method']}(");
            $end = $start === false ? false : strpos($src, "\n    }\n", $start);
            if ($start === false || $end === false) {
                throw new RuntimeException("No se encontró el método {$b['method']} en {$b['path']}");
            }
            file_put_contents($file, substr($src, 0, $start) . $b['code'] . substr($src, $end + 7));
            continue;
        }
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

test('guías: el proyecto Tareas funciona siguiendo los pasos en orden', function () {
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
            $u = $user['id'];
            $p = $project['id'];

            // Guía 4: tareas con fechas, regla propia, máquina de estados, guard y 409
            guideApply($app, '04-tareas-y-reglas.md');
            expect(guidePoint($app, $db, 'migrate')[0])->toContain('1 migraciones aplicadas');
            $title = escapeshellarg('Diseñar portada');
            $task = guideData(guidePoint($app, $db, "call tasks.create project_id=$p title=$title start_date=2030-01-07 due_date=2030-01-08 --as=$u --data"));
            expect($task['status'])->toBe('open');
            expect($task['priority'])->toBe(3);
            expect($task['author_id'])->toBe($u);
            expect(guidePoint($app, $db, "call tasks.create project_id=$p title=$title start_date=2030-01-08 due_date=2030-01-07 --as=$u")[1])->toBe(1);
            [$weekend, $weekendCode] = guidePoint($app, $db, "call tasks.create project_id=$p title=$title due_date=2030-01-05 --as=$u");
            expect($weekendCode)->toBe(1);
            expect($weekend)->toContain('El campo due_date no puede caer en fin de semana.');
            expect(guidePoint($app, $db, "call tasks.create project_id=$p title=$title due_date=2020-01-06 --as=$u")[1])->toBe(1);
            expect(guidePoint($app, $db, "call tasks.create project_id=999 title=$title --as=$u")[1])->toBe(1);
            expect(guidePoint($app, $db, "call tasks.create project_id=$p title=$title")[1])->toBe(1);

            expect(count(guideData(guidePoint($app, $db, "call projects.tasks id=$p --data"))))->toBe(1);
            expect(guideData(guidePoint($app, $db, "call projects.tasks id=$p status=done --data")))->toBe([]);
            expect(guidePoint($app, $db, "call projects.tasks id=$p status=volando")[1])->toBe(1);

            $t = $task['id'];
            expect(guideData(guidePoint($app, $db, "call tasks.status id=$t status=in_progress --as=$u --data"))['from'])->toBe('open');
            expect(guidePoint($app, $db, "call tasks.status id=$t status=done --as=$u")[1])->toBe(0);
            [$cancel, $cancelCode] = guidePoint($app, $db, "call tasks.status id=$t status=cancelled --as=$u");
            expect($cancelCode)->toBe(1);
            expect($cancel)->toContain("No se puede pasar de 'done' a 'cancelled'. Permitidos: open.");
            [$other] = guidePoint($app, $db, "call tasks.status id=$t status=open --as=" . ($u + 100));
            expect($other)->toContain('403');
            expect(guidePoint($app, $db, "call tasks.status id=9999 status=open --as=$u")[0])->toContain('Tarea no encontrada');

            // Guía 5: los tests que escribe el lector pasan, y también la prueba de humo
            guideApply($app, '05-tests-y-herramientas.md');
            [$tests, $testsCode] = guidePoint($app, $db, 'test');
            expect($tests)->toContain('7 tests, all passed');
            expect($testsCode)->toBe(0);
            [$smoke, $smokeCode] = guidePoint($app, $db, 'test --smoke');
            expect($smoke)->toContain('sin errores 5xx');
            expect($smokeCode)->toBe(0);
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
