# Guía 6: roles, CRUD y panel

**Tiempo:** 35 minutos · **Requisito previo:** [Guía 5](05-tests-y-herramientas.md)

Hasta ahora, en **Tareas** cualquier usuario con sesión puede hacer lo mismo que los demás. En esta guía
añadimos **roles** (administrador, editor y miembro), un catálogo de etiquetas con todas sus operaciones
en un solo archivo, un **panel** con el resumen de cada usuario y la subida de **archivos adjuntos**.

**Lo que aprenderás:**
- Dar un rol a cada usuario y proteger rutas por rol.
- Crear las cinco operaciones de un recurso (listar, ver, crear, editar y borrar) con `Endpoint::resource`.
- Juntar en una sola respuesta los datos que necesita una pantalla.
- Subir archivos y validar su tipo y tamaño.

---

## Paso 1: un rol para cada usuario

```bash
php point make:migration users alter
```

```php file=database/migrations/2026_01_01_000005_alter_users.table.php
<?php
use Core\Scribe\Table;

return Table::alter('users')
    ->addColumn('role')
        ->str(20)
        ->default('member');
```

Todos los usuarios empiezan como `member` (miembro). Creamos un administrador con un seed:

```bash
php point make:seed users
```

Reemplaza el archivo creado (será `database/seeds/02_users.seed.php`) por:

```php file=database/seeds/02_users.seed.php
<?php
return [
    'table' => 'users',
    'data' => [
        [
            'name'     => 'Admin',
            'email'    => 'admin@example.com',
            'password' => password_hash('admin12345', PASSWORD_DEFAULT),
            'role'     => 'admin',
        ],
    ],
];
```

Un seed es un archivo PHP: por eso puede cifrar la contraseña con `password_hash()`.

```bash
php point migrate
php point seed
```

> En un proyecto real, cambia la contraseña del administrador después del primer login.

## Paso 2: el rol viaja en el token

En `endpoints/login.php`, añade el rol al crear el token (solo cambia la línea de `Auth::issue`):

```php file=endpoints/login.php
<?php

use Core\Auth;
use Core\DB;
use Core\Endpoint;
use Core\Response;

Endpoint::from(__FILE__)
    ->at('POST /login')
    ->name('login')
    ->group('public')
    ->through('rate_limit:5')
    ->expects([
        'email'    => 'required|email|lowercase',
        'password' => 'required|string',
    ])
    ->handle(function ($input) {
        $user = DB::table('users')->where('email', $input['email'])->first();

        if (!$user) {
            password_hash($input['password'], PASSWORD_DEFAULT);
            return Response::error('Credenciales inválidas', 401);
        }

        if (!password_verify($input['password'], $user['password'])) {
            return Response::error('Credenciales inválidas', 401);
        }

        return Response::ok([
            'token'      => Auth::issue(['sub' => $user['id'], 'role' => $user['role']]),
            'expires_in' => (int) ($_ENV['JWT_TTL'] ?? 3600),
        ]);
    });
```

Los usuarios que ya tenían sesión deben volver a hacer login para recibir un token con su rol.

## Paso 3: rutas protegidas por rol

Los grupos están en `config/middleware.php`. La plantilla ya trae estos:

```php
'groups' => [
    'public'        => [],
    'protected'     => ['auth'],                 // cualquier usuario con sesión
    'authenticated' => ['auth'],
    'admin'         => ['auth:admin'],           // solo administradores
    'staff'         => ['auth:admin,editor'],    // administradores o editores
],
```

`auth:admin,editor` significa "con token, y con rol `admin` o `editor`". Puedes crear los grupos que
necesites siguiendo el mismo formato.

Creamos una ruta solo para administradores, la lista de usuarios:

```bash
php point make:endpoint users/list --group=admin
```

```php file=endpoints/users/list.php
<?php

use Core\DB;
use Core\Endpoint;
use Core\Response;

Endpoint::from(__FILE__)
    ->at('GET /users')
    ->name('users.list')
    ->group('admin')
    ->handle(fn () => Response::ok(
        DB::table('users')->select('id', 'name', 'email', 'role')->orderBy('id')->get()
    ));
```

```bash
php point call users.list --as=1                    # 403: el usuario 1 es miembro
php point call users.list --as=2 --role=admin       # 200: con rol admin
```

Con HTTP, inicia sesión con `admin@example.com` / `admin12345` y usa ese token. Sin token, la respuesta
es 401; con un rol que no está permitido, 403.

**Varios roles por usuario.** Si alguien es editor y además lleva la facturación, el token puede llevar
una lista: `Auth::issue(['sub' => 7, 'roles' => ['editor', 'billing']])`. Basta con que uno de sus
roles esté permitido. Dentro de un endpoint, `Auth::hasRole($input, 'admin')` dice si tiene un rol.

## Paso 4: un recurso completo en un archivo (etiquetas)

Las etiquetas ("Urgente", "Diseño"…) sirven para clasificar tareas. Las gestionan editores y
administradores, y necesitan las cinco operaciones habituales. En lugar de cinco archivos, usamos
`Endpoint::resource`.

```bash
php point make:migration labels
```

```php file=database/migrations/2026_01_01_000006_create_labels.table.php
<?php
use Core\Scribe\Table;

return Table::create('labels')
    ->id()
    ->str('name', 30)
    ->str('color', 7)
    ->stamp();
```

```bash
php point migrate
```

Crea `endpoints/labels.php` (a mano, no hace falta `make:endpoint`):

```php file=endpoints/labels.php
<?php

use App\Services\Rules;
use Core\DB;
use Core\Endpoint;
use Core\Response;

Endpoint::resource('/labels')
    ->group('staff')
    ->expects([                                   // se aplica al crear y al editar
        'name'  => Rules::text(max: 30, min: 2),
        'color' => ['required', 'regex:/^#[0-9a-f]{6}$/'],
    ])
    ->list(fn () => Response::ok(DB::table('labels')->orderBy('name')->get()))
    ->show(function ($input) {
        $label = DB::table('labels')->find((int) $input['id']);
        return $label ? Response::ok($label) : Response::notFound('Etiqueta no encontrada');
    })
    ->create(fn ($input) => Response::created(
        DB::table('labels')->insertReturning(['name' => $input['name'], 'color' => $input['color']], 'id, name, color')
    ))
    ->update(function ($input) {
        $changed = DB::table('labels')->where('id', (int) $input['id'])
            ->update(['name' => $input['name'], 'color' => $input['color']]);
        return $changed ? Response::ok(DB::table('labels')->find((int) $input['id'])) : Response::notFound('Etiqueta no encontrada');
    })
    ->delete(function ($input) {
        DB::table('labels')->where('id', (int) $input['id'])->delete();
        return Response::noContent();
    });
```

Este archivo crea cinco rutas, todas del grupo `staff`:

| Operación | Ruta | Nombre |
|---|---|---|
| `list` | `GET /labels` | `labels.list` |
| `show` | `GET /labels/{id}` | `labels.show` |
| `create` | `POST /labels` | `labels.create` |
| `update` | `PUT /labels/{id}` (o `PATCH`) | `labels.update` |
| `delete` | `DELETE /labels/{id}` | `labels.delete` |

- La regla de `color` va como **array** porque la expresión regular podría contener `|`, que en texto separa reglas.
- `Response::noContent()` responde **204**: correcto, sin cuerpo.
- `(int) $input['id']`: en un recurso, el `id` llega como texto desde la ruta.

```bash
php point call labels.create name=Urgente color=#ff0000 --as=2 --role=editor    # 201
php point call labels.create name=Urgente color=rojo --as=2 --role=editor       # 422: color no válido
php point call labels.list --as=1                                               # 403: un miembro no entra
php point routes                                                                # verás las cinco rutas
```

## Paso 5: un panel con una sola petición

Una pantalla de inicio suele necesitar datos de varios sitios: cuántos proyectos tengo, mis tareas
por estado, cuántas están vencidas. En lugar de que la aplicación haga tres peticiones, un endpoint
**agregador** lo junta todo:

```bash
php point make:endpoint dashboard
```

```php file=endpoints/dashboard.php
<?php

use App\Services\TaskFlow;
use Core\Auth;
use Core\DB;
use Core\Endpoint;
use Core\Response;

Endpoint::from(__FILE__)
    ->at('GET /dashboard')
    ->name('dashboard')
    ->group('protected')
    ->handle(function ($input) {
        $userId = Auth::id($input);

        $tasks = [];
        foreach (array_keys(TaskFlow::STEPS) as $status) {
            $tasks[$status] = DB::table('tasks')->where('author_id', $userId)->where('status', $status)->count();
        }

        return Response::ok([
            'projects' => DB::table('projects')->where('owner_id', $userId)->count(),
            'tasks'    => $tasks,
            'overdue'  => DB::table('tasks')
                ->where('author_id', $userId)
                ->where('due_date', '<', date('Y-m-d'))
                ->whereNotIn('status', ['done', 'cancelled'])
                ->count(),
        ]);
    });
```

```bash
php point call dashboard --as=1 --data
```

```json
{
    "projects": 1,
    "tasks": { "open": 0, "in_progress": 0, "done": 1, "cancelled": 0 },
    "overdue": 0
}
```

- Los estados salen de `TaskFlow::STEPS` (guía 4): si añades uno, el panel lo cuenta solo.
- Si esta lógica la necesitaran otros sitios, llévala a una acción (`make:action`) y llámala desde aquí, como en la [receta de la referencia](../REFERENCIA_ENDPOINT.md#receta-una-pantalla-una-petición).

**¿Y `connectTo`?** Point también puede **encadenar** endpoints: `->connectTo('otro.endpoint')` pasa la
respuesta de uno como entrada del siguiente y devuelve solo la del último. Sirve para flujos ("crear y
luego notificar"), **no** para juntar datos como aquí. En la guía 7 resolvemos los avisos con eventos,
que encajan mejor. Más detalle en la [referencia](../REFERENCIA_ENDPOINT.md#encadenar-endpoints-connectto).

## Paso 6: adjuntar archivos a una tarea

```bash
php point make:migration attachments
```

```php file=database/migrations/2026_01_01_000007_create_attachments.table.php
<?php
use Core\Scribe\Table;

return Table::create('attachments')
    ->id()
    ->int('task_id')->references('tasks')
    ->str('path')
    ->str('original_name')
    ->int('size_kb')
    ->stamp();
```

```bash
php point migrate
php point make:endpoint tasks/attachments --post
```

```php file=endpoints/tasks/attachments.php
<?php

use App\Services\Rules;
use Core\DB;
use Core\Endpoint;
use Core\Response;

Endpoint::from(__FILE__)
    ->at('POST /tasks/{id}/attachments')
    ->name('tasks.attachments')
    ->group('protected')
    ->through('rate_limit:10')
    ->expects([
        'id'   => Rules::id(),
        'file' => 'required|file|max_size:2048|mime:pdf,png,jpg',
    ])
    ->handle(function ($input) {
        if (!DB::table('tasks')->where('id', $input['id'])->exists()) {
            return Response::notFound('Tarea no encontrada');
        }

        $file = $input['file'];
        $path = $file->store("tasks/{$input['id']}");

        return Response::created(DB::table('attachments')->insertReturning([
            'task_id'       => $input['id'],
            'path'          => $path,
            'original_name' => $file->originalName(),
            'size_kb'       => (int) ceil($file->sizeKb()),
        ], 'id, task_id, path, original_name, size_kb'));
    });
```

- `file` exige un archivo; `max_size:2048`, como mucho 2 MB; `mime:pdf,png,jpg`, solo esos tipos.
  Point comprueba la extensión **y el contenido real** del archivo: un `.exe` renombrado a `.png` se rechaza.
- `store("tasks/1")` lo guarda en `storage/uploads/tasks/1/` con un nombre único y devuelve la ruta.
- `->through('rate_limit:10')`: como mucho 10 subidas por minuto.

Los archivos se envían como formulario (`multipart/form-data`), no como JSON:

```bash
curl -s -X POST http://localhost:8090/tasks/1/attachments \
  -H "Authorization: Bearer TU_TOKEN" -F "file=@/ruta/a/boceto.png"
```

> `storage/uploads` no se sube a git. En producción, guarda los adjuntos en un disco que no se borre al
> desplegar (un volumen) o en un servicio de almacenamiento.

## Paso 7: guardar el progreso

```bash
git add . && git commit -m "Guía 6: roles, etiquetas, panel y adjuntos"
```

---

## Resumen

- El rol se guarda en `users`, viaja en el token (`'role' => …`) y los grupos (`admin`, `staff`) lo exigen.
- `Endpoint::resource('/labels')` crea las cinco operaciones de un recurso en un archivo.
- Un endpoint **agregador** (`GET /dashboard`) junta en una respuesta lo que necesita una pantalla.
- `file`, `max_size` y `mime` validan archivos; `store()` los guarda en `storage/uploads`.
- `->through('rate_limit:N')` limita las peticiones por minuto de una ruta.

**Siguiente:** [Guía 7: rendimiento y segundo plano](07-rendimiento-y-segundo-plano.md), donde el panel
será más rápido, las tareas no se duplicarán y algunas tareas pesadas se harán en segundo plano.
