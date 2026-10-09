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

/** Ejecuta un comando cualquiera en el proyecto con el mismo entorno que guidePoint. */
function guideExec(string $app, array $db, string $cmd): array
{
    $env = 'env -u POINT_TESTING APP_ENV=development DB_DSN=' . escapeshellarg($db[0])
        . ' DB_USER=' . escapeshellarg($db[1]) . ' DB_PASS=' . escapeshellarg($db[2]);
    exec('cd ' . escapeshellarg($app) . " && $env $cmd < /dev/null 2>&1", $out, $code);
    return [implode("\n", $out), $code];
}

/** Servidor PHP del proyecto (subidas, cabeceras, asíncrono). Devuelve [puerto, proceso]. */
function guideServer(string $app, array $db): array
{
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    $env = array_merge(getenv(), ['APP_ENV' => 'development', 'DB_DSN' => $db[0], 'DB_USER' => $db[1], 'DB_PASS' => $db[2]]);
    unset($env['POINT_TESTING']);
    $null = ['file', '/dev/null', 'w'];
    // Sin OPcache: el test reescribe archivos al instante y no debe servirse una versión compilada antigua
    $proc = proc_open(['php', '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0', '-S', "127.0.0.1:$port", 'index.php'], [0 => ['file', '/dev/null', 'r'], 1 => $null, 2 => $null], $pipes, $app, $env);
    for ($i = 0; $i < 50; $i++) {
        usleep(100000);
        if ($fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1)) {
            fclose($fp);
            break;
        }
    }
    return [$port, $proc];
}

/** Petición al servidor del proyecto: [estado, cabeceras en minúsculas, cuerpo decodificado]. */
function guideHttp(int $port, string $method, string $path, array $headers = [], ?array $json = null): array
{
    $lines = ['Content-Type: application/json'];
    foreach ($headers as $k => $v) {
        $lines[] = "$k: $v";
    }
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $lines),
        'content' => $json === null ? '' : json_encode($json), 'ignore_errors' => true, 'timeout' => 15]]);
    $body = @file_get_contents("http://127.0.0.1:$port$path", false, $ctx);
    $status = 0;
    $h = [];
    foreach (http_get_last_response_headers() ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
            $status = (int) $m[1];
        } elseif (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $h[strtolower(trim($k))] = trim($v);
        }
    }
    return [$status, $h, json_decode((string) $body, true)];
}

/** Subida multipart con curl: [estado, cuerpo decodificado]. */
function guideUpload(int $port, string $path, string $file, string $token): array
{
    $out = (string) shell_exec('curl -s -w ' . escapeshellarg('\n%{http_code}') . ' -X POST -H ' . escapeshellarg("Authorization: Bearer $token")
        . ' -F ' . escapeshellarg("file=@$file") . ' ' . escapeshellarg("http://127.0.0.1:$port$path"));
    $pos = strrpos($out, "\n");
    return [(int) substr($out, $pos + 1), json_decode(substr($out, 0, $pos), true)];
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

            // Guía 6: roles, recurso de etiquetas, panel y adjuntos
            guideApply($app, '06-roles-crud-y-panel.md');
            expect(guidePoint($app, $db, 'migrate')[0])->toContain('3 migraciones aplicadas');
            expect(guidePoint($app, $db, 'seed')[0])->toContain('1 seeds ejecutados');
            $adminLogin = guideData(guidePoint($app, $db, 'call login email=admin@example.com password=admin12345 --data'));
            $claims = json_decode((string) base64_decode(strtr(explode('.', $adminLogin['token'])[1], '-_', '+/')), true);
            expect($claims['role'])->toBe('admin');
            expect(guidePoint($app, $db, "call users.list --as=$u")[0])->toContain('403');
            expect(count(guideData(guidePoint($app, $db, "call users.list --as={$claims['sub']} --role=admin --data"))))->toBeGreaterThan(1);

            $label = guideData(guidePoint($app, $db, "call labels.create name=Urgente color=#ff0000 --as=$u --role=editor --data"));
            expect($label['color'])->toBe('#ff0000');
            expect(guidePoint($app, $db, "call labels.create name=Urgente color=rojo --as=$u --role=editor")[1])->toBe(1);
            expect(guidePoint($app, $db, "call labels.list --as=$u")[0])->toContain('403');
            $l = $label['id'];
            $renamed = guideData(guidePoint($app, $db, "call labels.update id=$l name=" . escapeshellarg('Muy urgente') . " color=#00ff00 --as=$u --role=editor --data"));
            expect($renamed['name'])->toBe('Muy urgente');
            expect(guideData(guidePoint($app, $db, "call labels.show id=$l --as=$u --role=admin --data"))['color'])->toBe('#00ff00');
            expect(guidePoint($app, $db, "call labels.delete id=$l --as=$u --role=admin")[1])->toBe(0);
            expect(guidePoint($app, $db, "call labels.show id=$l --as=$u --role=admin")[0])->toContain('Etiqueta no encontrada');

            $dash = guideData(guidePoint($app, $db, "call dashboard --as=$u --data"));
            expect($dash['projects'])->toBeGreaterThan(0);
            expect($dash['tasks']['done'])->toBe(1);
            expect($dash['overdue'])->toBe(0);

            [$port, $server] = guideServer($app, $db);
            try {
                $token = Core\Auth::issue(['sub' => $u]);
                $png = tempnam(sys_get_temp_dir(), 'g6') . '.png';
                file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
                $fake = tempnam(sys_get_temp_dir(), 'g6') . '.png';
                file_put_contents($fake, '<?php echo "no soy una imagen";');
                [$upStatus, $upBody] = guideUpload($port, "/tasks/$t/attachments", $png, $token);
                expect($upStatus)->toBe(201);
                expect(is_file("$app/storage/uploads/{$upBody['data']['path']}"))->toBeTrue();
                expect(guideUpload($port, "/tasks/$t/attachments", $fake, $token)[0])->toBe(422);
                expect(guideUpload($port, '/tasks/9999/attachments', $png, $token)[0])->toBe(404);
                expect(guideUpload($port, "/tasks/$t/attachments", $png, 'token-falso')[0])->toBe(401);

                // Guía 7: caché, idempotencia, evento, job, scheduler y exportación asíncrona
                guideApply($app, '07-rendimiento-y-segundo-plano.md');
                $auth = ['Authorization' => "Bearer $token"];
                expect(guideHttp($port, 'GET', '/dashboard', $auth)[1]['x-cache'] ?? null)->toBe('MISS');
                expect(guideHttp($port, 'GET', '/dashboard', $auth)[1]['x-cache'] ?? null)->toBe('HIT');

                $key = ['Idempotency-Key' => 'guia7-' . bin2hex(random_bytes(4))];
                $first = guideHttp($port, 'POST', '/tasks', $auth + $key, ['project_id' => $p, 'title' => 'Preparar demo']);
                $again = guideHttp($port, 'POST', '/tasks', $auth + $key, ['project_id' => $p, 'title' => 'Preparar demo']);
                expect($first[0])->toBe(201);
                expect($again[2]['data']['id'])->toBe($first[2]['data']['id']);
                expect($again[1]['idempotent-replayed'] ?? null)->toBe('true');

                $log = fn () => (string) @file_get_contents("$app/storage/logs/" . date('Y-m-d') . '.log');
                expect($log())->toContain('aviso.tarea_creada');
                expect($log())->toContain('marta@example.com');

                (new PDO($dsn, $db[1], $db[2]))->exec("UPDATE tasks SET due_date = '2020-01-06', status = 'open' WHERE id = $t");
                $dispatch = 'php -r ' . escapeshellarg('define("POINT_NO_ROUTES", 1); require "vendor/autoload.php"; require "bootstrap.php"; dispatch(App\Jobs\RemindOverdueTasks::class);');
                expect(guideExec($app, $db, $dispatch)[1])->toBe(0);
                expect(guidePoint($app, $db, 'work --once')[1])->toBe(0);
                expect($log())->toContain('recordatorio.tarea_vencida');
                expect(guideExec($app, $db, 'php scheduler list')[0])->toContain('Recordar tareas vencidas');

                $sync = guideHttp($port, 'POST', "/projects/$p/export", $auth);
                expect($sync[0])->toBe(200);
                $rows = $sync[2]['data']['rows'];
                expect($rows)->toBeGreaterThan(1);
                $async = guideHttp($port, 'POST', "/projects/$p/export", $auth + ['Prefer' => 'respond-async']);
                expect($async[0])->toBe(202);
                expect(guidePoint($app, $db, 'work --once')[1])->toBe(0);
                $job = guideHttp($port, 'GET', '/jobs/' . $async[2]['job'], $auth)[2];
                expect($job['state'])->toBe('done');
                expect($job['progress'])->toBe(100);
                expect($job['result']['data']['rows'])->toBe($rows);
                expect(str_starts_with($job['result']['data']['csv'], "id,title,status,priority,due_date\n"))->toBeTrue();
            } finally {
                proc_terminate($server);
                proc_close($server);
            }
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
