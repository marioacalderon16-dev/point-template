# Guía: iniciar un proyecto nuevo con Point

Paso a paso desde la plantilla hasta una API desplegada, con base de datos, endpoints públicos y
protegidos, validación y tests. Ejemplo: tabla `users` sobre Postgres en Neon.

Requisitos: PHP ≥ 8.4 con `pdo_pgsql` (o `pdo_mysql`), Composer y git.

---

## Paso 1: generar el proyecto

Desde la carpeta de la plantilla (el nombre puede ser una ruta):

```bash
cd /ruta/a/la/plantilla/backend
php point init ../mi-api
```

Copia el core, `composer.json`/`composer.lock`, Docker (`Dockerfile`, `.dockerignore`), `railway.json`, `public/`, el endpoint
`/health`, el scheduler y los plugins `health` y `scheduler`. Genera además un `.env` con un
`JWT_SECRET` aleatorio.

## Paso 2: instalar dependencias

```bash
cd ../mi-api
composer install
php point plugin:list      # health y scheduler deben aparecer como "activo"
```

Cualquier comando `php point` antes de `composer install` dice «Ejecuta 'composer install' primero».

## Paso 3: configurar la base de datos en `.env`

El `.env` trae un bloque por proveedor. **Deja activo solo uno**: comenta (`#`) los demás. Si una
variable aparece dos veces, gana la primera.

> SQLite **no** está soportado: las migraciones se crean, pero el `id` no se autoincrementa
> (queda `NULL`). Usa el mismo motor en desarrollo y en producción.

Usuario y contraseña van en `DB_USER`/`DB_PASS`, nunca dentro de `DB_DSN`.

### Neon (Postgres)

Neon da una URL `postgresql://USUARIO:CLAVE@HOST/BASE?sslmode=require...`. Sepárala así, usando el
host **directo** (sin `-pooler`) para desarrollo y migraciones:

```
DB_DSN="pgsql:host=ep-xxxx.region.aws.neon.tech;port=5432;dbname=neondb;sslmode=require"
DB_USER="neondb_owner"
DB_PASS="tu-contraseña"
```

En producción puedes usar el host `-pooler` (PgBouncer, modo transacción) añadiendo
`DB_EMULATE_PREPARES=true`.

### Supabase (Postgres)

Dashboard → *Project Settings → Database → Connection string → Session pooler* (puerto **5432**,
no el 6543 de transacción):

```
DB_DSN="pgsql:host=aws-0-us-east-1.pooler.supabase.com;port=5432;dbname=postgres"
DB_USER="postgres.tu-project-ref"
DB_PASS="tu-contraseña"
```

### MySQL / MariaDB

```
DB_DSN="mysql:host=127.0.0.1;port=3306;dbname=mi_api;charset=utf8mb4"
DB_USER="root"
DB_PASS="secret"
```

### Probar la conexión

```bash
php -r 'require "vendor/autoload.php"; Dotenv\Dotenv::createImmutable(".")->load(); $p = new PDO($_ENV["DB_DSN"], $_ENV["DB_USER"], $_ENV["DB_PASS"]); echo $p->query("select version()")->fetchColumn(), "\n";'
```

Debe imprimir la versión (p. ej. `PostgreSQL 18.6 ...`).

Errores típicos:
- `tenant/user postgres.your-project-ref not found` → sigue activo el bloque de ejemplo de Supabase.
- `could not translate host name "ep-xxx..."` → el host sigue siendo el de ejemplo.

> Seguridad: no pegues la contraseña ni el `.env` en chats, issues o capturas. Si se filtra,
> cámbiala en el proveedor (Neon: *Roles → neondb_owner → Reset password*).

## Paso 4: arrancar el servidor y probar `/health`

```bash
php point serve 8090        # sin número usa 8080
```

Si sale `Address already in use`, otro programa ocupa ese puerto: usa otro número. Deja esa
terminal abierta y en otra:

```bash
curl -s http://localhost:8090/health
```

Debe devolver `"status": "ok"` con los checks `database`, `disk` y `queue`. La primera conexión a
Neon puede tardar unos segundos (la base «despierta»); las siguientes son rápidas.

## Paso 5: repositorio git

```bash
git init
git status --short | grep -i env     # solo debe salir .env.example, nunca .env
git add . && git commit -m "Proyecto inicial desde Point"
```

## Paso 6: primera migración

```bash
php point make:migration users
```

Edita `database/migrations/<fecha>_create_users.table.php`:

```php
<?php
use Core\Scribe\Table;

return Table::create('users')
    ->id()
    ->str('name')
    ->str('email')->unique()
    ->text('bio')->nullable()
    ->stamp();
```

`->unique()` / `->nullable()` modifican la columna anterior; `stamp()` añade `created_at` y
`updated_at`. Otros tipos: `int`, `bigInt`, `decimal`, `bool`, `date`, `timestamp`, `json`,
`uuid`, `enum`, `softDelete`, `references`.

```bash
php point migrate           # ✅ 1 migraciones aplicadas.
php point rollback          # (deshace la última, si lo necesitas)
```

## Paso 7: datos de prueba (seed)

```bash
php point make:seed users
```

Edita `database/seeds/01_users.seed.php`:

```php
<?php
return [
    'table' => 'users',
    'data' => [
        ['name' => 'Ana López', 'email' => 'ana@example.com', 'bio' => 'Primera usuaria'],
        ['name' => 'Luis Pérez', 'email' => 'luis@example.com'],
    ],
];
```

```bash
php point seed              # cada seed se ejecuta una sola vez
```

## Paso 8: endpoint de lectura `GET /users`

```bash
php point make:endpoint users --public
```

`endpoints/users.php`:

```php
<?php

use Core\DB;
use Core\Endpoint;

Endpoint::from(__FILE__)
    ->at('GET /users')
    ->name('users')
    ->group('public')
    ->handle(function ($input) {
        return [
            'status' => 200,
            'data' => DB::table('users')
                ->select('id', 'name', 'email', 'bio')
                ->orderBy('id')
                ->get(),
        ];
    });
```

```bash
php point routes                         # lista las rutas registradas
curl -s http://localhost:8090/users      # no hace falta reiniciar el servidor
```

## Paso 9: crear con validación `POST /users`

`endpoints/users.php` ya existe, así que se genera con otro nombre de archivo; la ruta la define
`->at()`:

```bash
php point make:endpoint users/create --post --public
```

`endpoints/users/create.php`:

```php
<?php

use Core\DB;
use Core\Endpoint;

Endpoint::from(__FILE__)
    ->at('POST /users')
    ->name('users.create')
    ->group('public')
    ->expects([
        'name'  => 'required|string|trim|min:2|max:255',
        'email' => 'required|email|lowercase|unique:users,email',
        'bio'   => 'optional|string|max:500',
    ])
    ->handle(function ($input) {
        $user = DB::table('users')->insertReturning($input, 'id, name, email, bio');

        return ['status' => 201, 'data' => $user];
    });
```

`expects` valida el cuerpo antes de `handle`; si falla responde 422 sin tocar la base de datos.
Convención REST: misma ruta, distinto método (`GET` lista, `POST` crea) y **201** al crear.

```bash
# correcto → 201
curl -s -X POST http://localhost:8090/users -H "Content-Type: application/json" -d '{"name":"Carla","email":"Carla@Example.com"}'
# inválido → 422
curl -s -X POST http://localhost:8090/users -H "Content-Type: application/json" -d '{"name":"C","email":"no-es-email"}'
# repetir el primero → 422 (email duplicado)
```

## Paso 10: tests automáticos

```bash
php point make:test users
```

Reemplaza `tests/users_test.php`:

```php
<?php

test('GET /users devuelve la lista', function () {
    $res = http('GET', '/users');
    expect($res->status)->toBe(200);
    expect(count($res->body['data']))->toBeGreaterThan(0);
});

test('POST /users crea un usuario', function () {
    $email = 'test_' . bin2hex(random_bytes(4)) . '@example.com';
    $res = http('POST', '/users', ['name' => 'Test', 'email' => $email]);
    expect($res->status)->toBe(201);
    expect($res->body['data']['email'])->toBe($email);
});

test('POST /users rechaza datos inválidos', function () {
    $res = http('POST', '/users', ['name' => 'X', 'email' => 'no-es-email']);
    expect($res->status)->toBe(422);
});

test('POST /users rechaza email duplicado', function () {
    $email = 'dup_' . bin2hex(random_bytes(4)) . '@example.com';
    http('POST', '/users', ['name' => 'Dup', 'email' => $email]);
    $res = http('POST', '/users', ['name' => 'Dup', 'email' => $email]);
    expect($res->status)->toBe(422);
});
```

```bash
php point test              # todos
php point test users        # solo los de este archivo
```

`php point test` levanta su propio servidor (no depende del de `serve`).

**Base de datos solo para tests.** Sin ella, los tests escriben en tu base de desarrollo (y
`point test` lo avisa). Crea `.env.testing` (git lo ignora) con la conexión a una base aparte;
tiene prioridad sobre `.env` y `point test` la migra antes de ejecutar los tests. Con Postgres
local (p. ej. `brew install postgresql@17 && brew services start postgresql@17`):

```bash
createdb mi_api_test
```

```
# .env.testing
DB_DSN="pgsql:host=127.0.0.1;port=5432;dbname=mi_api_test"
DB_USER="tu-usuario-del-sistema"
DB_PASS=""
```

En CI no hace falta: el workflow levanta un Postgres desechable por ejecución.

Si un test falla con `Expected 201, got 200` o `got 404`, revisa que el endpoint use la ruta y el
código de estado que el test espera.

Aserciones disponibles: `toBe`, `toEqual`, `toBeTrue`, `toBeFalse`, `toBeNull`, `toBeEmpty`,
`toContain`, `toHaveKey`, `toHaveCount`, `toBeGreaterThan`, `toBeLessThan`, `toMatch`,
`toBeInstanceOf`, `toThrow`, y `->not()` para negar.

## Paso 11: endpoint protegido con JWT `GET /me`

Grupos de `config/middleware.php`: `public` (sin middlewares), `protected` (exige token; es el
default de `make:endpoint`), `authenticated` (igual que `protected`) y `admin` (token con claim
`role` = `admin`). Un grupo no declarado responde 500 en lugar de dejar pasar la petición.

```bash
php point make:endpoint me
```

`endpoints/me.php`:

```php
<?php

use Core\DB;
use Core\Endpoint;

Endpoint::from(__FILE__)
    ->at('GET /me')
    ->name('me')
    ->group('protected')
    ->handle(function ($input) {
        return [
            'status' => 200,
            'data' => DB::table('users')
                ->select('id', 'name', 'email')
                ->where('id', $input['_user_id'])
                ->first(),
        ];
    });
```

El middleware `auth` verifica el token y deja el claim `sub` en `$input['_user_id']`.

```bash
# sin token → 401 "Token no proporcionado"
curl -s http://localhost:8090/me

# token de prueba para el usuario 1 (firmado con JWT_SECRET; caduca en JWT_TTL segundos)
php -r 'require "vendor/autoload.php"; Dotenv\Dotenv::createImmutable(".")->safeLoad(); echo Core\Auth::issue(["sub" => 1]), "\n";'

# con token → 200 con el usuario 1
curl -s http://localhost:8090/me -H "Authorization: Bearer TOKEN"
```

En un test: `$token = Core\Auth::issue(['sub' => 1]);` y
`http('GET', '/me', [], ['Authorization' => "Bearer $token"])`.

## Paso 12: guardar el progreso

```bash
git status --short          # .env no debe aparecer
git add . && git commit -m "Usuarios: migración, seed, GET/POST /users, GET /me con JWT y tests"
```

## Paso 13: login con contraseña `POST /login`

### 13.1 Columna `password`

```bash
php point make:migration users alter
```

`database/migrations/<fecha>_alter_users.table.php`:

```php
<?php
use Core\Scribe\Table;

return Table::alter('users')
    ->addColumn('password')
        ->str(255)
        ->nullable();
```

```bash
php point migrate
```

Es `nullable` porque los usuarios existentes no tienen contraseña (no podrán hacer login).

### 13.2 Registro con hash

`endpoints/users/create.php`:

```php
<?php

use Core\DB;
use Core\Endpoint;

Endpoint::from(__FILE__)
    ->at('POST /users')
    ->name('users.create')
    ->group('public')
    ->expects([
        'name'     => 'required|string|trim|min:2|max:255',
        'email'    => 'required|email|lowercase|unique:users,email',
        'password' => 'required|string|min:8|max:72',
        'bio'      => 'optional|string|max:500',
    ])
    ->handle(function ($input) {
        $input['password'] = password_hash($input['password'], PASSWORD_DEFAULT);
        $user = DB::table('users')->insertReturning($input, 'id, name, email, bio');

        return ['status' => 201, 'data' => $user];
    });
```

- **Todo campo que se guarde debe estar en `expects`**: los no declarados se descartan en
  silencio (sin `password` en `expects`, el usuario se crea con `password` NULL).
- **La línea `password_hash` es obligatoria**: sin ella la contraseña queda en texto plano y el
  login siempre responde 401. En la tabla, un hash válido empieza por `$2y$` y mide 60 caracteres.
- El `RETURNING` no incluye `password` (el hash nunca sale en la respuesta). 72 es el límite de bcrypt.

### 13.3 Endpoint de login

```bash
php point make:endpoint login --post --public
```

`endpoints/login.php`:

```php
<?php

use Core\Auth;
use Core\DB;
use Core\Endpoint;

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

        if (!$user || !$user['password']) {
            // Igual coste que password_verify: no revela si el email existe
            password_hash($input['password'], PASSWORD_DEFAULT);
            return ['status' => 401, 'message' => 'Credenciales inválidas'];
        }

        if (!password_verify($input['password'], $user['password'])) {
            return ['status' => 401, 'message' => 'Credenciales inválidas'];
        }

        return [
            'status' => 200,
            'data' => [
                'token'      => Auth::issue(['sub' => $user['id']]),
                'expires_in' => (int) ($_ENV['JWT_TTL'] ?? 3600),
            ],
        ];
    });
```

- `rate_limit:5`: máximo 5 intentos por minuto (fuerza bruta).
- Mismo mensaje y mismo tiempo de respuesta exista o no el email: si el usuario no existe se
  calcula igualmente un hash, para que no se pueda averiguar qué emails están registrados midiendo
  cuánto tarda la respuesta. No uses un hash «falso» escrito a mano: si no es un bcrypt válido,
  `password_verify` responde mucho más rápido y la diferencia se nota.
- `Class "Auth" not found` → falta `use Core\Auth;` arriba del archivo.

### 13.4 Probar

```bash
curl -s -X POST http://localhost:8090/users -H "Content-Type: application/json" -d '{"name":"Marta","email":"marta@example.com","password":"secreto123"}'   # 201
curl -s -X POST http://localhost:8090/login -H "Content-Type: application/json" -d '{"email":"marta@example.com","password":"secreto123"}'                  # 200 + token
curl -s -X POST http://localhost:8090/login -H "Content-Type: application/json" -d '{"email":"marta@example.com","password":"incorrecta"}'                  # 401
```

### 13.5 Tests

Añade `'password' => 'secreto123'` a todos los `http('POST', '/users', ...)` de los tests (si no,
dan 422; y el de «email duplicado» pasaría por el motivo equivocado). Tests de login:

```php
test('POST /login devuelve token con credenciales correctas', function () {
    $email = 'login_' . bin2hex(random_bytes(4)) . '@example.com';
    http('POST', '/users', ['name' => 'Login', 'email' => $email, 'password' => 'secreto123']);

    $res = http('POST', '/login', ['email' => $email, 'password' => 'secreto123']);
    expect($res->status)->toBe(200);
    expect($res->body['data'])->toHaveKey('token');

    $me = http('GET', '/me', [], ['Authorization' => 'Bearer ' . $res->body['data']['token']]);
    expect($me->body['data']['email'])->toBe($email);
});

test('POST /login rechaza contraseña incorrecta', function () {
    $res = http('POST', '/login', ['email' => 'marta@example.com', 'password' => 'incorrecta']);
    expect($res->status)->toBe(401);
});
```

Para ver por qué un test da 422, añade temporalmente `var_dump($res->body);`: el cuerpo lista los
errores de validación.

```bash
php point test
git add . && git commit -m "Login con contraseña: columna password, hash en registro, POST /login y tests"
```

## Paso 14: despliegue en Railway

La plantilla trae `Dockerfile` (Apache + PHP 8.5 + pdo_pgsql, `APP_ENV=production`),
`railway.json` (healthcheck en `/health`) y `.dockerignore` (no copia `.env`, `.git`, `tests/`
ni `vendor/` a la imagen).

### 14.1 Subir a GitHub

1. github.com → **New repository** → privado, **sin** README ni `.gitignore`.
2. Con la CLI de GitHub ya autenticada (`gh auth status`) lo más simple es HTTPS:

```bash
gh auth setup-git
git remote add origin https://github.com/TU_USUARIO/mi-api.git
git branch -M main && git push -u origin main
```

O todo en uno: `gh repo create mi-api --private --source . --push`.

- `Permission denied (publickey)` con una URL `git@...` → tu clave SSH no está registrada en
  GitHub; usa HTTPS como arriba (`git remote set-url origin https://...`).
- Comprueba en GitHub que **no** está `.env` (solo `.env.example`).

### 14.2 Crear el servicio

railway.com → **New Project → Deploy from GitHub repo** → elige el repositorio (autoriza la app
de Railway solo para ese repo). El primer despliegue falla: aún no hay variables.

### 14.3 Variables de entorno

Servicio → **Variables → Raw Editor**:

```
APP_ENV=production
TIME_ZONE=America/Bogota
LOG_LEVEL=info
DB_DSN=pgsql:host=HOST;port=5432;dbname=neondb;sslmode=require
DB_USER=neondb_owner
DB_PASS=tu-contraseña
JWT_SECRET=uno-nuevo-para-produccion
JWT_TTL=3600
QUEUE_SYNC=true
```

- `JWT_SECRET` **distinto** del de desarrollo: `php -r 'echo bin2hex(random_bytes(32)), "\n";'`.
  Por eso los tokens de desarrollo no sirven en producción (hay que hacer login allí).
- Base de datos: en un proyecto real, producción tiene su propia base (otro proyecto o rama de Neon).
- `QUEUE_SYNC=true` mientras no uses jobs; con jobs, `QUEUE_SYNC=false` y un servicio aparte con
  `php point work`.
- `CORS_ORIGINS=https://tu-frontend.com` cuando haya frontend (en producción, sin esta variable no
  se envía `Access-Control-Allow-Origin`).

**Settings → Networking → Generate Domain** (puerto `8080` si lo pide). Al guardar variables,
Railway vuelve a desplegar; en verde significa que `/health` respondió.

### 14.4 Migraciones en producción (automáticas)

`railway.json` ejecuta `php /var/www/html/scribe migrate` como **pre-deploy command**: en cada
despliegue, Railway aplica las migraciones pendientes en un contenedor aparte (con las variables
del servicio) **antes** de arrancar la versión nueva. Si la migración falla, el despliegue no
continúa (el código nuevo no se publica con un esquema roto); revisa el log del despliegue.

- Mientras se migra, la versión anterior sigue sirviendo tráfico: escribe migraciones
  compatibles con el código anterior (p. ej. columnas nuevas `nullable`, no renombrar ni borrar
  columnas en el mismo despliegue que deja de usarlas).
- Para aplicarlas a mano (o en otra base), desde tu máquina; las variables del proceso tienen
  prioridad sobre `.env`:

```bash
DB_DSN="pgsql:host=HOST_PROD;port=5432;dbname=neondb;sslmode=require" DB_USER=neondb_owner DB_PASS=... php point migrate
```

### 14.5 Probar en producción

```bash
URL=https://tu-app.up.railway.app
curl -s $URL/health
curl -s -X POST $URL/login -H "Content-Type: application/json" -d '{"email":"marta@example.com","password":"secreto123"}'
curl -s $URL/me -H "Authorization: Bearer TOKEN"
curl -s $URL/me -H "Authorization: Bearer token-falso"     # 401
curl -s -i $URL/health | head -20                          # cabeceras de seguridad, sin versión de PHP/Apache
```

En producción los errores 500 no muestran archivo, línea ni traza (en `development` sí).

---

**Flujo de trabajo:** desarrollas en local (`php point serve 8090`, `php point test`), haces commit
y `git push`, y Railway despliega solo cada push a `main`.
