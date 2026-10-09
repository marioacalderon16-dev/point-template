# Guía 2: base de datos y primera tabla

**Tiempo:** 25 minutos · **Requisito previo:** [Guía 1](01-hola.md)

En esta guía conectamos **Tareas** a una base de datos y creamos la tabla `projects`, con endpoints para
listar, ver y crear proyectos.

**Lo que aprenderás:**
- Configurar la conexión a Postgres o MySQL.
- Crear tablas con migraciones y cargar datos de prueba con seeds.
- Leer y guardar datos con `DB::table()`.
- Validar con las reglas de Point y con los atajos de `Rules`.
- Parámetros en la ruta (`/projects/{id}`), paginación y el código 404.

---

## Paso 1: elegir una base de datos

Point funciona con **Postgres** o **MySQL/MariaDB** (SQLite no está soportado). Elige una:

- **Neon** o **Supabase**: Postgres gratis en la nube, sin instalar nada.
- **Postgres local**: `brew install postgresql@17 && brew services start postgresql@17` (macOS), y luego `createdb tareas`.
- **MySQL local**: el que ya tengas instalado.

## Paso 2: configurar `.env`

Abre `.env`. Trae un bloque de ejemplo por proveedor: **deja activo solo uno** y comenta (`#`) los
demás. El usuario y la contraseña van siempre en `DB_USER` y `DB_PASS`, nunca dentro de `DB_DSN`.

**Neon.** Neon te da una URL `postgresql://USUARIO:CLAVE@HOST/BASE?...`. Sepárala así, usando el host
**directo** (sin `-pooler`):

```
DB_DSN="pgsql:host=ep-xxxx.region.aws.neon.tech;port=5432;dbname=neondb;sslmode=require"
DB_USER="neondb_owner"
DB_PASS="tu-contraseña"
```

**Supabase.** En *Project Settings → Database → Connection string → Session pooler* (puerto **5432**):

```
DB_DSN="pgsql:host=aws-0-us-east-1.pooler.supabase.com;port=5432;dbname=postgres"
DB_USER="postgres.tu-project-ref"
DB_PASS="tu-contraseña"
```

**Postgres local:**

```
DB_DSN="pgsql:host=127.0.0.1;port=5432;dbname=tareas"
DB_USER="tu-usuario-del-sistema"
DB_PASS=""
```

**MySQL:**

```
DB_DSN="mysql:host=127.0.0.1;port=3306;dbname=tareas;charset=utf8mb4"
DB_USER="root"
DB_PASS="secret"
```

Comprueba la conexión:

```bash
curl -s http://localhost:8090/health
```

Ahora `"database"` debe decir `"status": "ok"`. Si dice `error`, el detalle está en el log:

```bash
php point logs --level=error --lines=5
```

Errores típicos:
- `could not translate host name` → el host sigue siendo el de ejemplo.
- `tenant/user postgres.your-project-ref not found` → sigue activo el bloque de ejemplo de Supabase.
- `password authentication failed` → revisa `DB_USER` y `DB_PASS`.

> Nunca pegues el `.env` ni la contraseña en chats o capturas. Si se filtra, cámbiala en el proveedor.

## Paso 3: crear la tabla con una migración

Una **migración** es un archivo que describe un cambio en la base de datos (crear una tabla, añadir una
columna). Así el esquema queda guardado en git y se puede repetir en cualquier base.

```bash
php point make:migration projects
```

Crea `database/migrations/<fecha>_create_projects.table.php` (la fecha es la de hoy). Reemplaza su
contenido por:

```php file=database/migrations/2026_01_01_000001_create_projects.table.php
<?php
use Core\Scribe\Table;

return Table::create('projects')
    ->id()
    ->str('name')
    ->text('description')->nullable()
    ->stamp();
```

- `id()`: columna `id` que se numera sola.
- `str('name')`: texto de hasta 255 caracteres.
- `text('description')->nullable()`: texto largo, que puede quedar vacío (`nullable` modifica la columna anterior).
- `stamp()`: añade `created_at` y `updated_at`.

Aplícala:

```bash
php point migrate           # ✅ 1 migraciones aplicadas.
```

Si te equivocas, `php point rollback` deshace la última migración: la corriges y vuelves a `migrate`.

## Paso 4: datos de prueba con un seed

Un **seed** carga datos iniciales, útiles para probar.

```bash
php point make:seed projects
```

Reemplaza `database/seeds/01_projects.seed.php` por:

```php file=database/seeds/01_projects.seed.php
<?php
return [
    'table' => 'projects',
    'data' => [
        ['name' => 'Web corporativa', 'description' => 'Rediseño de la página principal'],
        ['name' => 'App móvil', 'description' => 'Primera versión para iOS y Android'],
        ['name' => 'Mudanza de oficina'],
    ],
];
```

```bash
php point seed              # cada seed se ejecuta una sola vez
```

## Paso 5: listar proyectos (`GET /projects`)

Los endpoints de un mismo tema se agrupan en una carpeta. Crea el listado:

```bash
php point make:endpoint projects/list --public
```

Reemplaza `endpoints/projects/list.php` por:

```php file=endpoints/projects/list.php
<?php

use App\Services\Rules;
use Core\DB;
use Core\Endpoint;
use Core\Response;

Endpoint::from(__FILE__)
    ->at('GET /projects')
    ->name('projects.list')
    ->group('public')
    ->expects([
        ...Rules::pagination(),
    ])
    ->handle(function ($input) {
        $result = DB::table('projects')
            ->select('id', 'name', 'description')
            ->orderBy('id')
            ->paginate($input['limit'], $input['page']);

        return Response::paginated($result);
    });
```

- La ruta la define `->at()`, no el nombre del archivo: `projects/list.php` responde en `GET /projects`.
- `...Rules::pagination()` añade dos campos opcionales: `page` (por defecto 1) y `limit` (por defecto 20, máximo 100).
- `DB::table('projects')` construye la consulta; `paginate()` devuelve la página pedida y el total.
- `Response::paginated()` responde con `data` (las filas) y `meta` (total, página actual, última página).

```bash
curl -s "http://localhost:8090/projects"
curl -s "http://localhost:8090/projects?limit=2&page=2"
```

## Paso 6: ver un proyecto (`GET /projects/{id}`)

```bash
php point make:endpoint projects/show --public
```

Reemplaza `endpoints/projects/show.php` por:

```php file=endpoints/projects/show.php
<?php

use App\Services\Rules;
use Core\DB;
use Core\Endpoint;
use Core\Response;

Endpoint::from(__FILE__)
    ->at('GET /projects/{id}')
    ->name('projects.show')
    ->group('public')
    ->expects([
        'id' => Rules::id(),
    ])
    ->handle(function ($input) {
        $project = DB::table('projects')->where('id', $input['id'])->first();

        return $project ? Response::ok($project) : Response::notFound('Proyecto no encontrado');
    });
```

- `{id}` en la ruta es un **parámetro**: en `/projects/2`, `id` vale `2`.
- `Rules::id()` exige un entero mayor o igual que 1, y lo convierte a número.
- `first()` devuelve la primera fila, o `null` si no hay ninguna.

```bash
curl -s http://localhost:8090/projects/2      # 200 con el proyecto
curl -s http://localhost:8090/projects/999    # 404 "Proyecto no encontrado"
curl -s http://localhost:8090/projects/abc    # 422: id debe ser un número entero
```

## Paso 7: crear proyectos (`POST /projects`)

```bash
php point make:endpoint projects/create --post --public
```

Reemplaza `endpoints/projects/create.php` por:

```php file=endpoints/projects/create.php
<?php

use App\Services\Rules;
use Core\DB;
use Core\Endpoint;
use Core\Response;

Endpoint::from(__FILE__)
    ->at('POST /projects')
    ->name('projects.create')
    ->group('public')
    ->expects([
        'name'        => Rules::text(max: 100, min: 3),
        'description' => Rules::text(max: 1000, required: false),
    ])
    ->handle(function ($input) {
        $project = DB::table('projects')->insertReturning($input, 'id, name, description');

        return Response::created($project);
    });
```

- `Rules::text(max: 100, min: 3)`: texto obligatorio, sin espacios sobrantes, de 3 a 100 caracteres.
- `required: false` lo hace opcional.
- **Solo llegan a `$input` los campos de `expects`.** Si el cliente envía otros (por ejemplo `"id": 1`), se descartan. Por eso es seguro guardar `$input` directamente.
- `insertReturning()` inserta y devuelve la fila creada. `Response::created()` responde **201**.

Pruébalo:

```bash
# correcto → 201
curl -s -X POST http://localhost:8090/projects -H "Content-Type: application/json" \
  -d '{"name":"Lanzamiento","description":"Campaña de otoño"}'

# inválido → 422 con el motivo de cada campo
curl -s -X POST http://localhost:8090/projects -H "Content-Type: application/json" -d '{"name":"X"}'
```

O desde la terminal: `php point call projects.create name=Lanzamiento`.

## Paso 8: probar todo con un clic

```bash
php point make:http
```

Genera `requests.http` con una petición de ejemplo por cada ruta. Ábrelo en VS Code (extensión
*REST Client*) o en PhpStorm y pulsa *Send Request* sobre cada una.

## Paso 9: guardar el progreso

```bash
git add . && git commit -m "Guía 2: tabla projects, seed y endpoints de proyectos"
```

---

## Resumen

- `.env` guarda la conexión; `/health` y `php point logs` ayudan a comprobarla.
- Las **migraciones** crean y cambian tablas (`make:migration`, `migrate`, `rollback`); los **seeds** cargan datos (`make:seed`, `seed`).
- `DB::table()` consulta (`select`, `where`, `orderBy`, `first`, `paginate`) y guarda (`insertReturning`).
- `Rules::id()`, `Rules::text()` y `Rules::pagination()` ahorran escribir reglas; solo llegan los campos declarados.
- Respuestas: `Response::ok()` (200), `created()` (201), `paginated()` y `notFound()` (404).

**Siguiente:** [Guía 3: usuarios y login](03-usuarios-y-login.md), donde cada proyecto tendrá un dueño
y solo los usuarios con sesión podrán crearlos.
