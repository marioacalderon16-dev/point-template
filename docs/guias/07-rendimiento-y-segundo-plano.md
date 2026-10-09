# Guía 7: rendimiento y segundo plano

**Tiempo:** 40 minutos · **Requisito previo:** [Guía 6](06-roles-crud-y-panel.md)

**Tareas** ya hace todo lo necesario. Ahora lo hacemos **más rápido y más fiable**: el panel responderá
sin consultar la base cada vez, una tarea no se creará dos veces por un doble clic, y los trabajos
pesados o repetitivos (exportar, avisar, recordar) se harán **en segundo plano**.

**Lo que aprenderás:**
- Guardar respuestas en caché con `->cache()`.
- Evitar duplicados con `->idempotent()`.
- Reaccionar a lo que pasa en la aplicación con **eventos** y **listeners**.
- Crear **jobs**, ejecutarlos con el **worker** y programarlos con el **scheduler**.
- Hacer que un endpoint lento responda al momento con un ticket (asíncrono).
- Qué son los **plugins**.

---

## Paso 1: un panel más rápido con caché

El panel de la guía 6 hace seis consultas en cada petición. Si el usuario recarga la página a menudo,
podemos guardar la respuesta unos segundos. En `endpoints/dashboard.php`, añade `->cache(30)`:

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
    ->cache(30)
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
curl -s -i http://localhost:8090/dashboard -H "Authorization: Bearer TU_TOKEN" | grep X-Cache    # X-Cache: MISS
curl -s -i http://localhost:8090/dashboard -H "Authorization: Bearer TU_TOKEN" | grep X-Cache    # X-Cache: HIT
```

- La primera petición se calcula y se guarda (`MISS`); durante 30 segundos, las siguientes reciben la copia (`HIT`).
- La copia es **por usuario**: nadie ve el panel de otro.
- El precio: si creas una tarea, el panel puede tardar hasta 30 segundos en reflejarla. Elige el tiempo según cuánto pueda esperar cada dato.

## Paso 2: tareas sin duplicados

Si la conexión falla justo al pulsar "Crear tarea", la aplicación reintenta y la tarea puede crearse
dos veces. La solución estándar es que el cliente envíe una **clave única por operación**
(`Idempotency-Key`). En `endpoints/tasks/create.php`, añade `->idempotent()`:

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
    ->idempotent()
    ->expects(CreateTask::rules())
    ->handle(fn ($input) => Response::created((new CreateTask)($input, Auth::id($input))));
```

```bash
# Dos veces la misma petición con la misma clave → la misma tarea (la segunda lleva Idempotent-Replayed: true)
curl -s -X POST http://localhost:8090/tasks -H "Authorization: Bearer TU_TOKEN" -H "Content-Type: application/json" \
  -H "Idempotency-Key: alta-0001" -d '{"project_id":1,"title":"Preparar demo"}'
```

- Con la misma clave y los mismos datos, Point devuelve la respuesta guardada **sin crear otra tarea**.
- Con la misma clave y **otros** datos, responde 422; si la primera aún se está procesando, 409.
- Sin la cabecera, el endpoint funciona como siempre.
- En el frontend: genera la clave al pulsar el botón (`crypto.randomUUID()`) y reutilízala en los reintentos.

## Paso 3: avisar al dueño del proyecto con un evento

Queremos que, cuando alguien cree una tarea, se avise al dueño del proyecto. Podríamos escribir el aviso
dentro de `CreateTask`, pero mañana querremos además registrarlo, contarlo en estadísticas… La acción
crecería sin parar. En su lugar, la acción **anuncia** lo que pasó (un **evento**) y otros archivos
(**listeners**) reaccionan.

Primero, la acción lanza el evento `task.created` después de guardar. Reemplaza
`services/Actions/CreateTask.php`:

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
        $row = array_intersect_key($data, self::rules());

        $task = DB::table('tasks')->insertReturning(
            [...$row, 'author_id' => $authorId, 'status' => 'open'],
            'id, project_id, title, status, priority, start_date, due_date, author_id'
        );

        event('task.created', $task);    // quien escuche este evento reacciona

        return $task;
    }
}
```

Después, el listener que avisa:

```bash
php point make:listener NotifyProjectOwner
```

```php file=listeners/NotifyProjectOwner.php
<?php
namespace App\Listeners;

use Core\DB;
use Core\Log;

class NotifyProjectOwner
{
    public function handle(array $payload, string $event): void
    {
        $owner = DB::table('projects')
            ->join('users', 'users.id', '=', 'projects.owner_id')
            ->select('users.email', 'projects.name')
            ->where('projects.id', $payload['project_id'])
            ->first();

        if ($owner === null) {
            return; // proyecto sin dueño
        }

        // Aquí iría el envío del email; de momento lo registramos en el log
        Log::info('aviso.tarea_creada', ['para' => $owner['email'], 'proyecto' => $owner['name'], 'tarea' => $payload['title']]);
    }
}
```

Por último, se conecta el evento con su listener en `config/events.php` (créalo si no existe):

```php file=config/events.php
<?php

return [
    'task.created' => [
        App\Listeners\NotifyProjectOwner::class,
    ],
];
```

Crea una tarea y mira el log:

```bash
php point call tasks.create project_id=1 title="Revisar textos" --as=1
php point logs --grep=aviso --lines=1
```

- Un evento puede tener varios listeners: añádelos a la lista sin tocar la acción.
- Si un listener falla, el error queda en el log y la tarea se crea igualmente.
- Si el aviso fuera lento (enviar un email real), `Core\Event::fireAsync('task.created', $task)` en lugar de `event()` lo manda a la cola del paso siguiente.

## Paso 4: un job para recordar las tareas vencidas

Un **job** es un trabajo que se ejecuta fuera de la petición: lo deja en una **cola** quien lo necesita
y lo procesa el **worker**, un proceso aparte.

```bash
php point make:job RemindOverdueTasks
```

```php file=jobs/RemindOverdueTasks.php
<?php
namespace App\Jobs;

use Core\DB;
use Core\Log;

class RemindOverdueTasks
{
    public function handle(array $data): void
    {
        $overdue = DB::table('tasks')
            ->join('users', 'users.id', '=', 'tasks.author_id')
            ->select('tasks.id', 'tasks.title', 'tasks.due_date', 'users.email')
            ->where('tasks.due_date', '<', date('Y-m-d'))
            ->whereNotIn('tasks.status', ['done', 'cancelled'])
            ->get();

        foreach ($overdue as $task) {
            Log::info('recordatorio.tarea_vencida', ['para' => $task['email'], 'tarea' => $task['title'], 'vencio' => $task['due_date']]);
        }
    }

    public function failed(array $data, \Throwable $e): void
    {
        Log::error('recordatorio.fallo', ['error' => $e->getMessage()]);
    }
}
```

Para probarlo necesitamos una tarea vencida. Como la validación no deja crear fechas pasadas, cambia
una con SQL desde la consola de tu base de datos:

```sql
UPDATE tasks SET due_date = '2020-01-06', status = 'open' WHERE id = 1;
```

Ahora encola el job y procésalo con el worker:

```bash
php -r 'define("POINT_NO_ROUTES", 1); require "vendor/autoload.php"; require "bootstrap.php"; dispatch(App\Jobs\RemindOverdueTasks::class);'
php point work --once                        # procesa un job y termina
php point logs --grep=recordatorio --lines=5
```

- `dispatch(Clase::class, $datos)` deja el job en la cola (`storage/jobs`).
- `php point work` lo procesa. Sin `--once` se queda escuchando y procesa los que lleguen: así se usa en producción.
- Si un job falla varias veces, va a la lista de fallidos: `php point work:failed` los muestra y `work:retry` los reintenta.
- En desarrollo puedes poner `QUEUE_SYNC=true` en `.env`: los jobs se ejecutan al momento, sin worker.

## Paso 5: ejecutarlo cada mañana con el scheduler

No queremos lanzar el recordatorio a mano. El **scheduler** ejecuta tareas a horas fijas. Las tareas se
declaran en `config/schedule.php`; reemplázalo por:

```php file=config/schedule.php
<?php

use App\Jobs\RemindOverdueTasks;
use Core\Middleware\RateLimitMiddleware;
use Plugins\Scheduler\Scheduler;

// Cron del servidor (uno solo): * * * * * cd /ruta/app && php scheduler run >> storage/logs/cron.log 2>&1

// Contadores del rate limit vencidos (storage/ratelimit); el tope por shard es la red de seguridad
Scheduler::call(fn () => RateLimitMiddleware::purgeExpired())
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->description('Limpiar contadores de rate limit vencidos');

// Recordatorio de tareas vencidas, cada día a las 8:00
Scheduler::job(RemindOverdueTasks::class)
    ->dailyAt('08:00')
    ->description('Recordar tareas vencidas');
```

```bash
php scheduler list          # muestra las tareas y cuándo se ejecutan
php scheduler run           # ejecuta las que tocan en este minuto
```

- La primera tarea ya venía en la plantilla (limpia los contadores del límite de peticiones): consérvala.
- `Scheduler::job()` deja el job en la cola a su hora; lo procesa el worker. `Scheduler::call()` ejecuta una función directamente.
- Otras frecuencias: `everyMinute()`, `hourly()`, `daily()`, `weeklyOn(1, '9:00')`, `cron('*/10 * * * *')`…
- En el servidor basta **una sola línea** en el cron, que ejecuta `php scheduler run` cada minuto (la del comentario del archivo).

## Paso 6: exportar en segundo plano (asíncrono)

Exportar todas las tareas de un proyecto a CSV puede tardar si hay muchas. Con `->asyncable()`, el
cliente puede pedir que se haga en segundo plano: recibe un **ticket** al momento y consulta el
resultado después.

```bash
php point make:endpoint projects/export --post
```

```php file=endpoints/projects/export.php
<?php

use App\Services\Rules;
use Core\DB;
use Core\Endpoint;
use Core\Job;
use Core\Response;

Endpoint::from(__FILE__)
    ->at('POST /projects/{id}/export')
    ->name('projects.export')
    ->group('protected')
    ->asyncable()
    ->expects(['id' => Rules::id()])
    ->handle(function ($input) {
        $tasks = DB::table('tasks')
            ->select('id', 'title', 'status', 'priority', 'due_date')
            ->where('project_id', $input['id'])
            ->orderBy('id')
            ->get();

        $csv = "id,title,status,priority,due_date\n";
        foreach ($tasks as $i => $task) {
            $csv .= implode(',', array_map(fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"', $task)) . "\n";
            Job::progress((int) (($i + 1) * 100 / count($tasks)));     // visible en /jobs/{id}
        }

        return Response::ok(['rows' => count($tasks), 'csv' => $csv]);
    });
```

Sin la cabecera, funciona como siempre (espera y responde). **Con** `Prefer: respond-async`:

```bash
curl -s -X POST http://localhost:8090/projects/1/export -H "Authorization: Bearer TU_TOKEN" -H "Prefer: respond-async"
```

```json
{ "status": 202, "job": "7f3a9c…", "state": "queued", "status_url": "/jobs/7f3a9c…" }
```

Procesa la cola y consulta el ticket:

```bash
php point work --once
curl -s http://localhost:8090/jobs/7f3a9c… -H "Authorization: Bearer TU_TOKEN"
```

```json
{ "status": 200, "job": "7f3a9c…", "state": "done", "progress": 100, "result_status": 200, "result": { "data": { "rows": 2, "csv": "id,title,…" } } }
```

- Permisos y validación se comprueban **antes** de encolar: un error llega al momento, no después.
- Solo quien lanzó el trabajo puede ver su ticket.
- `Job::progress(…)` informa del avance mientras tanto (`"state": "running", "progress": 58`).
- `->async()` (sin "able") lo haría **siempre** en segundo plano, con o sin cabecera.

## Paso 7: los plugins

Point mantiene el núcleo pequeño y añade funciones mediante **plugins**. El scheduler que acabas de
usar es uno, y `/health`, otro:

```bash
php point plugin:list
```

- `php point plugin:add <nombre>` instala un plugin disponible y `plugin:remove` lo desactiva.
- `php point make:plugin <nombre>` crea el esqueleto de uno propio, para reutilizar algo entre proyectos.
- Los plugins activos se declaran en `config/plugins.php`.

## Paso 8: guardar el progreso

```bash
git add . && git commit -m "Guía 7: caché, idempotencia, eventos, jobs, scheduler y exportación asíncrona"
```

---

## Resumen

- `->cache(30)` guarda la respuesta por usuario; `->idempotent()` evita duplicados con `Idempotency-Key`.
- Una acción lanza **eventos** (`event('task.created', …)`) y los **listeners** de `config/events.php` reaccionan.
- Los **jobs** van a la cola (`dispatch()`) y los procesa el **worker** (`php point work`).
- El **scheduler** (`config/schedule.php`) los lanza a su hora con una sola línea en el cron.
- `->asyncable()` convierte un endpoint lento en un ticket que se consulta en `/jobs/{id}`.
- Los **plugins** amplían Point sin engordar el núcleo.

**Siguiente:** [Guía 8: a producción](08-produccion.md), donde publicamos la API con el worker, el
scheduler, la CI y un asistente de IA.
