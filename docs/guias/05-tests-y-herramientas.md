# Guía 5: tests y herramientas

**Tiempo:** 20 minutos · **Requisito previo:** [Guía 4](04-tareas-y-reglas.md)

**Tareas** ya tiene usuarios, proyectos y tareas con reglas. Cada vez que cambies algo, ¿cómo sabes
que no rompiste nada? En esta guía escribimos **tests automáticos** y conocemos las herramientas que
hacen más cómodo el día a día.

**Lo que aprenderás:**
- Escribir tests que llaman a tu API y comprueban las respuestas.
- Usar una base de datos aparte para los tests.
- Detectar endpoints rotos sin escribir tests (prueba de humo).
- Probar y documentar la API con `requests.http` y Swagger.
- Leer los logs y usar `point call` en scripts.

---

## Paso 1: una base de datos solo para tests

Los tests crean usuarios, proyectos y tareas. Para no llenar tu base de desarrollo, crea otra:

```bash
createdb tareas_test        # Postgres local; en Neon o Supabase, crea otra base o rama
```

Crea `.env.testing` (git lo ignora, igual que `.env`) con su conexión:

```
DB_DSN="pgsql:host=127.0.0.1;port=5432;dbname=tareas_test"
DB_USER="tu-usuario-del-sistema"
DB_PASS=""
```

`php point test` la usa en lugar de la de `.env` y **aplica las migraciones** antes de empezar. Si no
la creas, los tests usan tu base de desarrollo (y Point te lo avisa).

## Paso 2: tu primer archivo de tests

```bash
php point make:test projects
```

Reemplaza `tests/projects_test.php` por:

```php file=tests/projects_test.php
<?php

require_once __DIR__ . '/helpers.php';

test('GET /projects devuelve la lista paginada', function () {
    $res = http('GET', '/projects');
    expect($res->status)->toBe(200);
    expect($res->body)->toHaveKey('meta');
});

test('POST /projects exige iniciar sesión', function () {
    $res = http('POST', '/projects', ['name' => 'Sin sesión']);
    expect($res->status)->toBe(401);
});

test('POST /projects valida el nombre', function () {
    $res = http('POST', '/projects', ['name' => 'X'], conToken(nuevoUsuario()));
    expect($res->status)->toBe(422);
    expect($res->body['errors'])->toHaveKey('name');
});
```

Cómo se lee un test:
- `test('descripción', function () { ... })` define un test.
- `http('MÉTODO', '/ruta', $datos, $cabeceras)` llama a tu API y devuelve la respuesta: `->status` (código) y `->body` (el JSON como array).
- `expect($valor)->toBe(...)` comprueba el resultado. Si no se cumple, el test falla y te dice qué esperaba y qué recibió.

La primera línea carga `tests/helpers.php`, con dos ayudas (`nuevoUsuario()` y `conToken()`) que
creamos en el siguiente paso.

## Paso 3: ayudas para los tests

Muchos tests necesitan un usuario con sesión. En vez de repetirlo, lo ponemos en un archivo de ayudas.
Los archivos de `tests/` que no terminan en `_test.php` no se ejecutan como tests, así que
`tests/helpers.php` es un buen sitio:

```php file=tests/helpers.php
<?php

/** Crea un usuario con un email único y devuelve su id. */
function nuevoUsuario(): int
{
    $email = 'test_' . bin2hex(random_bytes(4)) . '@example.com';
    $res = http('POST', '/users', ['name' => 'Tester', 'email' => $email, 'password' => 'secreto123']);
    return $res->body['data']['id'];
}

/** Cabecera Authorization con un token del usuario indicado. */
function conToken(int $userId): array
{
    return ['Authorization' => 'Bearer ' . Core\Auth::issue(['sub' => $userId])];
}
```

`Core\Auth::issue()` crea el token directamente, sin pasar por `POST /login`. Así los tests no chocan
con el límite de 5 intentos por minuto del login.

## Paso 4: tests de las reglas de negocio

```bash
php point make:test tasks
```

```php file=tests/tasks_test.php
<?php

require_once __DIR__ . '/helpers.php';

/** Crea un proyecto del usuario y devuelve su id. */
function nuevoProyecto(int $userId): int
{
    return http('POST', '/projects', ['name' => 'Proyecto de prueba'], conToken($userId))->body['data']['id'];
}

test('crear una tarea válida: empieza abierta y con prioridad 3', function () {
    $user = nuevoUsuario();
    $res = http('POST', '/tasks', [
        'project_id' => nuevoProyecto($user),
        'title'      => 'Diseñar portada',
        'start_date' => '2030-01-07',
        'due_date'   => '2030-01-08',
    ], conToken($user));

    expect($res->status)->toBe(201);
    expect($res->body['data']['status'])->toBe('open');
    expect($res->body['data']['priority'])->toBe(3);
});

test('la entrega no puede ser antes del inicio ni en fin de semana', function () {
    $user = nuevoUsuario();
    $project = nuevoProyecto($user);

    $antes = http('POST', '/tasks', ['project_id' => $project, 'title' => 'Tarea', 'start_date' => '2030-01-08', 'due_date' => '2030-01-07'], conToken($user));
    expect($antes->status)->toBe(422);

    $sabado = http('POST', '/tasks', ['project_id' => $project, 'title' => 'Tarea', 'due_date' => '2030-01-05'], conToken($user));
    expect($sabado->status)->toBe(422);
    expect($sabado->body['errors']['due_date'][0])->toBe('El campo due_date no puede caer en fin de semana.');
});

test('los estados siguen el mapa y solo el autor puede cambiarlos', function () {
    $user = nuevoUsuario();
    $task = http('POST', '/tasks', ['project_id' => nuevoProyecto($user), 'title' => 'Tarea'], conToken($user))->body['data']['id'];

    expect(http('PATCH', "/tasks/$task/status", ['status' => 'in_progress'], conToken($user))->status)->toBe(200);
    expect(http('PATCH', "/tasks/$task/status", ['status' => 'done'], conToken($user))->status)->toBe(200);

    $cancelar = http('PATCH', "/tasks/$task/status", ['status' => 'cancelled'], conToken($user));
    expect($cancelar->status)->toBe(422);
    expect($cancelar->body['errors']['status'][0])->toContain("No se puede pasar de 'done' a 'cancelled'");

    $otro = nuevoUsuario();
    expect(http('PATCH', "/tasks/$task/status", ['status' => 'open'], conToken($otro))->status)->toBe(403);
});

test('el login devuelve un token que sirve en /me', function () {
    $email = 'login_' . bin2hex(random_bytes(4)) . '@example.com';
    http('POST', '/users', ['name' => 'Login', 'email' => $email, 'password' => 'secreto123']);

    $login = http('POST', '/login', ['email' => $email, 'password' => 'secreto123']);
    expect($login->status)->toBe(200);

    $me = http('GET', '/me', [], ['Authorization' => 'Bearer ' . $login->body['data']['token']]);
    expect($me->body['data']['email'])->toBe($email);
});
```

Cada test crea **sus propios datos** (usuarios con emails únicos): así no dependen unos de otros ni del
orden en que se ejecuten.

## Paso 5: ejecutar los tests

```bash
php point test              # todos
php point test tasks        # solo los de tests/tasks_test.php
```

```
  tests/projects_test.php
    OK  GET /projects devuelve la lista paginada
    OK  POST /projects exige iniciar sesión
    OK  POST /projects valida el nombre
  tests/tasks_test.php
    OK  crear una tarea válida: empieza abierta y con prioridad 3
    ...
  7 tests, all passed
```

`php point test` levanta su propio servidor: no hace falta tener `serve` en marcha. Si un test falla
con un 422 que no esperabas, añade temporalmente `var_dump($res->body);` dentro del test: el cuerpo
lista los errores de validación.

Otras comprobaciones: `toEqual`, `toBeTrue`, `toBeFalse`, `toBeNull`, `toContain`, `toHaveKey`,
`toHaveCount`, `toBeGreaterThan`, `toMatch`… y `->not()` para negar (`expect($x)->not()->toBe(1)`).

## Paso 6: prueba de humo, sin escribir tests

```bash
php point test --smoke
```

Llama a **todas** las rutas GET con datos de ejemplo y falla si alguna responde con un error del
servidor (5xx). Detecta endpoints rotos en segundos. Las rutas protegidas reciben un token automático.

`--smoke=all` prueba también POST, PUT, PATCH y DELETE, que **escriben** en la base: úsalo solo con
`.env.testing`.

## Paso 7: probar a mano con `requests.http`

```bash
php point make:http --force
```

Regenera `requests.http` con todas las rutas actuales (`--force` sobrescribe el de la guía 2). Ábrelo en
VS Code (extensión *REST Client*) o PhpStorm, pega un token en la línea `@token = …` y pulsa
*Send Request* en cada petición.

## Paso 8: documentación interactiva con Swagger

```bash
php point openapi --serve
```

Abre `http://localhost:8081`: verás todos los endpoints con sus campos y reglas, generados a partir de
tu código. Pulsa **Try it out** en uno para probarlo. Para las rutas protegidas, pulsa **Authorize**
y pega un token (el de `POST /login`).

Para compartir la documentación sin servidor, `php point openapi` genera solo el archivo `openapi.json`.

## Paso 9: leer los logs

Cuando algo va mal, Point lo registra en `storage/logs`. Para verlo con colores:

```bash
php point logs                     # últimas 50 líneas de hoy
php point logs --level=error       # solo errores
php point logs --grep=tasks -f     # sigue en vivo lo que contenga "tasks" (Ctrl+C para salir)
```

Para escribir tus propios registros desde el código: `Core\Log::info('tarea.creada', ['id' => $task['id']])`.

## Paso 10: tu API en scripts

`point call` ejecuta un endpoint desde la terminal, con sus reglas y permisos. Devuelve 0 si fue bien,
1 con un error del cliente (4xx) y 2 con un error del servidor (5xx), así que sirve en scripts:

```bash
#!/bin/sh
set -e                                                    # se detiene al primer error
ID=$(php point call projects.create name="Sprint 12" --as=1 --data | php -r 'echo json_decode(stream_get_contents(STDIN))->id;')
php point call tasks.create project_id=$ID title="Planificar" --as=1
php point call tasks.create project_id=$ID title="Revisar" priority=5 --as=1
echo "Proyecto $ID creado con sus tareas"
```

## Paso 11: guardar el progreso

```bash
git add . && git commit -m "Guía 5: tests de proyectos y tareas"
```

---

## Resumen

- Los tests (`make:test`, `http()`, `expect()`) comprueban tu API automáticamente. Ejecútalos con `php point test`.
- `.env.testing` separa la base de los tests de la de desarrollo.
- `php point test --smoke` detecta rutas rotas sin escribir tests.
- `make:http`, `openapi --serve` y `logs` ayudan a probar, documentar y depurar.
- `point call` lleva tus endpoints a scripts y al cron.

**Siguiente:** [Guía 6: roles, CRUD y panel](06-roles-crud-y-panel.md), donde cada usuario tendrá un rol
y la API ganará un panel y archivos adjuntos.
