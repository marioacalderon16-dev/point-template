# Guía 4: tareas con reglas de negocio

**Tiempo:** 30 minutos · **Requisito previo:** [Guía 3](03-usuarios-y-login.md)

Hasta ahora validábamos datos sueltos: "el nombre tiene entre 3 y 100 caracteres". Las aplicaciones
reales tienen **reglas de negocio**: "la entrega no puede ser antes del inicio", "una tarea terminada
no se puede cancelar", "solo el autor puede cambiarla". En esta guía añadimos tareas a **Tareas** y
dejamos que Point haga cumplir esas reglas.

**Lo que aprenderás:**
- Comparar un campo con otro o con una fecha (`after_or_equal`).
- Crear tus propias reglas de validación.
- Definir los estados de una tarea y qué cambios están permitidos (máquina de estados).
- Comprobar permisos con `guard` y responder errores propios con `onError`.
- Evitar que dos cambios simultáneos se pisen (409).

---

## Paso 1: tabla de tareas

```bash
php point make:migration tasks
```

```php file=database/migrations/2026_01_01_000004_create_tasks.table.php
<?php
use Core\Scribe\Table;

return Table::create('tasks')
    ->id()
    ->int('project_id')->references('projects')
    ->str('title')
    ->text('description')->nullable()
    ->str('status', 20)->default('open')
    ->int('priority')->default(3)
    ->date('start_date')->nullable()
    ->date('due_date')->nullable()
    ->int('author_id')->references('users')
    ->stamp();
```

- Cada tarea pertenece a un proyecto (`project_id`) y tiene un autor (`author_id`).
- `status` empieza en `open` (abierta) y `priority` en 3 (de 1 a 5).
- `start_date` (inicio) y `due_date` (entrega) son opcionales.

```bash
php point migrate           # ✅ 1 migraciones aplicadas.
```

## Paso 2: los estados de una tarea

Una tarea pasa por varios estados, pero **no todos los cambios tienen sentido**: una tarea cancelada no
debería volver a "en curso", y una terminada no se cancela. Escribimos el mapa de cambios permitidos
en un solo sitio:

```php file=services/TaskFlow.php
<?php
declare(strict_types=1);
namespace App\Services;

/** Estados de una tarea y los cambios permitidos desde cada uno. */
final class TaskFlow
{
    public const STEPS = [
        'open'        => ['in_progress', 'cancelled'],
        'in_progress' => ['open', 'done', 'cancelled'],
        'done'        => ['open'],          // se puede reabrir
        'cancelled'   => [],                // estado final: ya no cambia
    ];
}
```

Se lee así: desde `open` se puede pasar a `in_progress` o a `cancelled`; desde `done`, solo volver a
`open`; desde `cancelled`, a ningún sitio.

```
open ──► in_progress ──► done
  │          │  ▲          │
  │          ▼  └──────────┘ (reabrir)
  └──────► cancelled
```

## Paso 3: una regla propia, "no en fin de semana"

Point trae muchas reglas (`email`, `min`, `date`…), pero algunas son de tu negocio. Supongamos que
**las entregas no pueden caer en sábado ni domingo**. Las reglas propias se añaden en el método
`custom()` de `services/Rules.php`. Reemplaza ese método por:

```php file=services/Rules.php method=custom
    public static function custom(): array
    {
        return [
            'slug' => [
                fn ($value) => is_string($value) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) === 1,
                'El campo :field solo admite minúsculas, números y guiones.',
            ],
            'workday' => [
                fn ($value) => ($time = strtotime((string) $value)) !== false && !in_array(date('N', $time), ['6', '7'], true),
                'El campo :field no puede caer en fin de semana.',
            ],
        ];
    }
```

- Cada regla tiene un nombre (`workday`), una comprobación que devuelve `true` si el valor es válido, y un mensaje (`:field` se cambia por el nombre del campo).
- `date('N', …)` da el día de la semana: 6 es sábado y 7, domingo.
- Desde ahora, `'workday'` funciona en cualquier endpoint, igual que una regla de Point.

## Paso 4: la acción para crear tareas

```bash
php point make:action CreateTask
```

```php file=services/Actions/CreateTask.php
<?php
declare(strict_types=1);
namespace App\Services\Actions;

use App\Services\Rules;
use Core\DB;

final class CreateTask
{
    public static function rules(): array
    {
        return [
            'project_id'  => [...Rules::id(), 'exists:projects,id'],
            'title'       => Rules::text(max: 150, min: 3),
            'description' => Rules::text(max: 2000, required: false),
            'priority'    => 'optional|integer|between:1,5|default:3',
            'start_date'  => Rules::date(required: false),
            'due_date'    => [...Rules::date(required: false), 'after_or_equal:today', 'after_or_equal:start_date', 'workday'],
        ];
    }

    public function __invoke(array $data, int $authorId): array
    {
        // Solo los campos de rules(): $data puede traer también los datos del usuario
        $row = array_intersect_key($data, self::rules());

        return DB::table('tasks')->insertReturning(
            [...$row, 'author_id' => $authorId, 'status' => 'open'],
            'id, project_id, title, status, priority, start_date, due_date, author_id'
        );
    }
}
```

Las reglas, una a una:

| Campo | Reglas | Qué significa |
|---|---|---|
| `project_id` | `Rules::id()`, `exists:projects,id` | Un ID válido **y** que el proyecto exista en la base de datos |
| `priority` | `optional\|integer\|between:1,5\|default:3` | Opcional, de 1 a 5; si no viene, vale 3 |
| `due_date` | `after_or_equal:today` | La entrega no puede ser en el pasado (`today` es un valor fijo) |
| `due_date` | `after_or_equal:start_date` | …ni antes del inicio (aquí se compara con **otro campo**) |
| `due_date` | `workday` | …ni en fin de semana (la regla del paso 3) |

`array_intersect_key($data, self::rules())` se queda solo con los campos declarados: en una ruta
protegida, `$data` también trae los datos del usuario, que no son columnas de la tabla.

## Paso 5: crear tareas (`POST /tasks`)

```bash
php point make:endpoint tasks/create --post
```

```php file=endpoints/tasks/create.php
<?php

use App\Services\Actions\CreateTask;
use Core\Auth;
use Core\Endpoint;
use Core\Response;

Endpoint::from(__FILE__)
    ->at('POST /tasks')
    ->name('tasks.create')
    ->group('protected')
    ->expects(CreateTask::rules())
    ->handle(fn ($input) => Response::created((new CreateTask)($input, Auth::id($input))));
```

Prueba las reglas (usa el token de la guía 3 en `TU_TOKEN`, y un proyecto que exista):

```bash
# correcto → 201, con status "open" y priority 3
curl -s -X POST http://localhost:8090/tasks -H "Authorization: Bearer TU_TOKEN" -H "Content-Type: application/json" \
  -d '{"project_id":1,"title":"Diseñar portada","start_date":"2030-01-07","due_date":"2030-01-08"}'

# entrega antes del inicio → 422
curl -s -X POST http://localhost:8090/tasks -H "Authorization: Bearer TU_TOKEN" -H "Content-Type: application/json" \
  -d '{"project_id":1,"title":"Diseñar portada","start_date":"2030-01-08","due_date":"2030-01-07"}'

# entrega en sábado → 422 "El campo due_date no puede caer en fin de semana."
curl -s -X POST http://localhost:8090/tasks -H "Authorization: Bearer TU_TOKEN" -H "Content-Type: application/json" \
  -d '{"project_id":1,"title":"Diseñar portada","due_date":"2030-01-05"}'
```

Más cómodo desde la terminal: `php point call tasks.create project_id=1 title="Diseñar portada" due_date=2030-01-08 --as=1`.

## Paso 6: tareas de un proyecto (`GET /projects/{id}/tasks`)

```bash
php point make:endpoint projects/tasks --public
```

```php file=endpoints/projects/tasks.php
<?php

use App\Services\Rules;
use App\Services\TaskFlow;
use Core\DB;
use Core\Endpoint;
use Core\Response;

Endpoint::from(__FILE__)
    ->at('GET /projects/{id}/tasks')
    ->name('projects.tasks')
    ->group('public')
    ->expects([
        'id'     => Rules::id(),
        'status' => Rules::status(array_keys(TaskFlow::STEPS), required: false),
    ])
    ->handle(function ($input) {
        $query = DB::table('tasks')
            ->select('id', 'title', 'status', 'priority', 'due_date')
            ->where('project_id', $input['id']);

        if ($input['status'] !== null) {
            $query->where('status', $input['status']);
        }

        return Response::ok($query->orderBy('priority', 'DESC')->orderBy('id')->get());
    });
```

`Rules::status(array_keys(TaskFlow::STEPS))` acepta solo estados que existen. Como sale del mismo mapa,
si mañana añades un estado, la validación lo conoce sin tocar este archivo.

```bash
curl -s "http://localhost:8090/projects/1/tasks"
curl -s "http://localhost:8090/projects/1/tasks?status=done"
curl -s "http://localhost:8090/projects/1/tasks?status=volando"     # 422
```

## Paso 7: cambiar el estado (`PATCH /tasks/{id}/status`)

Aquí se juntan tres reglas: el cambio debe estar permitido por el mapa, solo el autor puede hacerlo, y
dos cambios a la vez no deben pisarse.

```bash
php point make:endpoint tasks/status --patch
```

```php file=endpoints/tasks/status.php
<?php

use App\Services\Rules;
use App\Services\TaskFlow;
use Core\Auth;
use Core\DB;
use Core\Endpoint;
use Core\Response;

Endpoint::from(__FILE__)
    ->at('PATCH /tasks/{id}/status')
    ->name('tasks.status')
    ->group('protected')
    ->guard(function ($input) {
        // Solo el autor (si la tarea no existe, deja pasar: el handler responde 404)
        $task = DB::table('tasks')->select('author_id')->where('id', (int) ($input['id'] ?? 0))->first();
        return $task === null || (int) $task['author_id'] === (int) Auth::id($input);
    })
    ->expects([
        'id'     => Rules::id(),
        'status' => Rules::transition('tasks', TaskFlow::STEPS),
    ])
    ->onError(fn (Throwable $e) => Response::error('No se pudo cambiar el estado. Inténtalo de nuevo.', 503))
    ->handle(function ($input) {
        $task = DB::table('tasks')->where('id', $input['id'])->first();
        if (!$task) {
            return Response::notFound('Tarea no encontrada');
        }

        // Solo actualiza si nadie la cambió desde que la leímos
        $updated = DB::table('tasks')
            ->where('id', $input['id'])
            ->where('status', $task['status'])
            ->update(['status' => $input['status']]);

        if ($updated === 0) {
            return Response::error('La tarea cambió mientras tanto. Vuelve a cargarla.', 409);
        }

        return Response::ok(['id' => $input['id'], 'from' => $task['status'], 'status' => $input['status']]);
    });
```

Cada parte, en el orden en que Point la ejecuta:

1. **`group('protected')`**: sin token, 401.
2. **`guard(...)`**: una comprobación propia antes de validar. Si devuelve `false`, Point responde **403**. Ojo: recibe los datos **sin validar** (por eso convertimos `id` con `(int)`).
3. **`Rules::transition('tasks', TaskFlow::STEPS)`**: lee el estado actual de la tarea y solo admite los cambios del mapa. Si no, **422** con un mensaje que explica qué se puede hacer.
4. **`handle(...)`**: el `update` con `->where('status', $task['status'])` solo cambia la fila si sigue en el estado que leímos. Si otra petición la cambió justo antes, actualiza 0 filas y respondemos **409**.
5. **`onError(...)`**: si algo falla de forma inesperada (por ejemplo, se cae la base de datos), responde este mensaje en lugar de un error genérico.

Pruébalo con la tarea 1 (creada por el usuario 1):

```bash
php point call tasks.status id=1 status=in_progress --as=1    # 200: open → in_progress
php point call tasks.status id=1 status=done --as=1           # 200: in_progress → done
php point call tasks.status id=1 status=cancelled --as=1      # 422: No se puede pasar de 'done' a 'cancelled'. Permitidos: open.
php point call tasks.status id=1 status=open --as=2           # 403: el usuario 2 no es el autor
```

**Extra:** el mismo mapa sirve fuera de los endpoints. `Rules::canTransition(TaskFlow::STEPS, 'done', 'open')`
devuelve `true` (útil en un script o un job), y `Rules::diagram(TaskFlow::STEPS)` genera un diagrama
para tu README:

```bash
php -r 'require "vendor/autoload.php"; echo App\Services\Rules::diagram(App\Services\TaskFlow::STEPS);'
```

## Paso 8: guardar el progreso

```bash
git add . && git commit -m "Guía 4: tareas con fechas, estados, guard y regla propia"
```

---

## Resumen

- **Comparar campos:** `after_or_equal:start_date` compara con otro campo; `after_or_equal:today`, con un valor fijo.
- **Reglas propias** en `Rules::custom()`: un nombre, una comprobación y un mensaje.
- **Máquina de estados:** el mapa `TaskFlow::STEPS` y `Rules::transition()` impiden cambios no permitidos.
- **`guard`** decide quién puede (403); **`onError`** personaliza los errores inesperados.
- `->where('status', $leído)->update(...)` más un **409** evita que dos cambios se pisen.

**Siguiente:** [Guía 5: tests y herramientas](05-tests-y-herramientas.md), donde comprobamos todo esto
automáticamente.
