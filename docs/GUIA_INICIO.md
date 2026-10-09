# Guías de inicio de Point

Aprende Point construyendo, paso a paso, **Tareas**: una API para gestionar proyectos y tareas. Cada
guía es corta, añade algo al mismo proyecto y se apoya en la anterior. Para consultar una función
concreta, usa la [referencia](REFERENCIA_ENDPOINT.md).

| # | Guía | Qué construyes | Qué aprendes | Tiempo |
|---|---|---|---|---|
| 1 | [Hola, Point](guias/01-hola.md) | El proyecto y `GET /hola` | Crear y arrancar un proyecto, escribir un endpoint, `routes`, `call` | 10 min |
| 2 | [Base de datos y primera tabla](guias/02-base-de-datos.md) | Tabla `projects`: listar, ver y crear | Conexión, migraciones, seeds, `DB::table()`, validación, `Rules`, paginación, 404 | 25 min |
| 3 | [Usuarios y login](guias/03-usuarios-y-login.md) | Registro, login, `GET /me` y proyectos con dueño | Contraseñas, acciones, JWT, grupo `protected`, `Auth::id()`, límite de intentos | 30 min |
| 4 | Tareas con reglas de negocio *(próximamente)* | Tareas con estados, fechas y prioridad | Comparar campos, máquina de estados, reglas propias, `guard`, `onError` | 30 min |
| 5 | Tests y herramientas *(próximamente)* | Tests del proyecto y documentación | `make:test`, prueba de humo, `make:http`, Swagger, logs | 20 min |
| 6 | Roles, CRUD y panel *(próximamente)* | Roles, CRUD de proyectos, panel y adjuntos | Grupos y roles, `Endpoint::resource`, agregador, `connectTo`, archivos | 35 min |
| 7 | Rendimiento y segundo plano *(próximamente)* | Caché, altas sin duplicados, exportación en segundo plano, avisos y recordatorios | `cache`, `idempotent`, asíncrono, jobs, eventos, scheduler, plugins | 40 min |
| 8 | A producción *(próximamente)* | Despliegue y asistente de IA | Docker, Railway (web + worker), CI, `point mcp` | 30 min |

**Necesitas:** PHP 8.4 o superior, [Composer](https://getcomposer.org/), git y (desde la guía 2) una
base de datos Postgres o MySQL.

---

## Mientras se completan las guías 4 a 8

Estos apartados siguen el proyecto de la guía 3 y pasarán a sus guías cuando estén listas.

## Tests automáticos

`php point test` ejecuta los tests de `tests/` levantando su propio servidor (no depende del de
`serve`).

```bash
php point make:test projects
```

Reemplaza `tests/projects_test.php`:

```php
<?php

test('GET /projects devuelve la lista paginada', function () {
    $res = http('GET', '/projects');
    expect($res->status)->toBe(200);
    expect($res->body)->toHaveKey('meta');
});

test('POST /projects exige token', function () {
    expect(http('POST', '/projects', ['name' => 'Sin sesión'])->status)->toBe(401);
});

test('POST /projects crea un proyecto con dueño', function () {
    $token = Core\Auth::issue(['sub' => 1]);
    $res = http('POST', '/projects', ['name' => 'Desde un test'], ['Authorization' => "Bearer $token"]);
    expect($res->status)->toBe(201);
    expect($res->body['data']['owner_id'])->toBe(1);
});

test('POST /users rechaza datos inválidos', function () {
    $res = http('POST', '/users', ['name' => 'X', 'email' => 'no-es-email', 'password' => '123']);
    expect($res->status)->toBe(422);
});
```

El tercer test necesita que exista el usuario 1 (créalo antes con `POST /users`).

```bash
php point test              # todos
php point test projects     # solo los de este archivo
php point test --smoke      # llama a todas las rutas GET y falla si alguna da 5xx
```

**Base de datos solo para tests.** Sin ella, los tests escriben en tu base de desarrollo (y
`point test` lo avisa). Crea `.env.testing` (git lo ignora) con la conexión a una base aparte; tiene
prioridad sobre `.env` y `point test` la migra antes de ejecutar los tests:

```
# .env.testing
DB_DSN="pgsql:host=127.0.0.1;port=5432;dbname=tareas_test"
DB_USER="tu-usuario-del-sistema"
DB_PASS=""
```

En CI no hace falta: el workflow levanta un Postgres desechable por ejecución.

Aserciones disponibles: `toBe`, `toEqual`, `toBeTrue`, `toBeFalse`, `toBeNull`, `toBeEmpty`,
`toContain`, `toHaveKey`, `toHaveCount`, `toBeGreaterThan`, `toBeLessThan`, `toMatch`,
`toBeInstanceOf`, `toThrow`, y `->not()` para negar. Para ver por qué un test da 422, añade
temporalmente `var_dump($res->body);`: el cuerpo lista los errores de validación.

## Herramientas del día a día

**Documentación interactiva (Swagger).** `php point openapi` genera `openapi.json` a partir de las rutas y sus
reglas, y `php point openapi --serve` lo abre con Swagger UI en `http://localhost:8081`. Desde ahí se ven
todos los endpoints con sus campos y se pueden probar con el botón «Try it out». Para las rutas protegidas,
pulsa «Authorize» y pega un token (por ejemplo, el de `POST /login`).

**Logs.** `php point logs` muestra los últimos registros de `storage/logs` con colores por nivel:

```bash
php point logs --lines=100            # últimas 100 líneas de hoy
php point logs --level=error          # solo errores
php point logs --grep=login -f        # sigue en vivo las líneas que contienen "login" (Ctrl+C para salir)
php point logs --date=2026-10-01      # otro día
```

**Endpoints desde la terminal.** `php point call users.create name=Ana email=ana@example.com password=secreto123`
ejecuta el endpoint con su validación y permisos (`--as=1` para actuar como un usuario). Ver
[la referencia](REFERENCIA_ENDPOINT.md#point-call).

**Trabajos en segundo plano.** Las rutas con `->asyncable()` o `->async()` y los jobs necesitan el worker:
`php point work`. En desarrollo basta con `QUEUE_SYNC=true` en `.env`; en producción, el worker va como un
proceso aparte (en Railway, un segundo servicio con el mismo repositorio y el comando `php point work`).

## Despliegue en Railway

La plantilla trae `Dockerfile` (Apache + PHP 8.5 + pdo_pgsql, `APP_ENV=production`),
`railway.json` (healthcheck en `/health`) y `.dockerignore` (no copia `.env`, `.git`, `tests/`
ni `vendor/` a la imagen).

### Subir a GitHub

1. github.com → **New repository** → privado, **sin** README ni `.gitignore`.
2. Con la CLI de GitHub ya autenticada (`gh auth status`) lo más simple es HTTPS:

```bash
gh auth setup-git
git remote add origin https://github.com/TU_USUARIO/tareas.git
git branch -M main && git push -u origin main
```

O todo en uno: `gh repo create tareas --private --source . --push`.

- `Permission denied (publickey)` con una URL `git@...` → tu clave SSH no está registrada en
  GitHub; usa HTTPS como arriba (`git remote set-url origin https://...`).
- Comprueba en GitHub que **no** está `.env` (solo `.env.example`).

### Crear el servicio

railway.com → **New Project → Deploy from GitHub repo** → elige el repositorio (autoriza la app
de Railway solo para ese repo). El primer despliegue falla: aún no hay variables.

### Variables de entorno

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

### Migraciones en producción (automáticas)

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

### Probar en producción

```bash
URL=https://tu-app.up.railway.app
curl -s $URL/health
curl -s -X POST $URL/login -H "Content-Type: application/json" -d '{"email":"marta@example.com","password":"secreto123"}'
curl -s $URL/me -H "Authorization: Bearer TOKEN"
curl -s $URL/me -H "Authorization: Bearer token-falso"     # 401
curl -s -i $URL/health | head -20                          # cabeceras de seguridad, sin versión de PHP/Apache
```

En producción los errores 500 no muestran archivo, línea ni traza (en `development` sí).

## Sin Railway, Supabase ni Neon

Ninguno es obligatorio: Point necesita una base de datos Postgres o MySQL y un sitio donde ejecutar
PHP 8.4+.

### Base de datos

Cualquier Postgres o MySQL/MariaDB sirve (local, servidor propio, AWS RDS, DigitalOcean…): solo
cambian `DB_DSN`, `DB_USER` y `DB_PASS`. SQLite no está soportado.

```
DB_DSN="pgsql:host=mi-servidor;port=5432;dbname=mi_api"
DB_DSN="mysql:host=mi-servidor;port=3306;dbname=mi_api;charset=utf8mb4"
```

**CI con MySQL:** en `.github/workflows/ci.yml`, sustituye el servicio `postgres` por el bloque
`mysql` comentado y cambia `DB_DSN` como indica el comentario del archivo, para que los tests se
ejecuten contra el mismo motor que usas.

### Hosting con Docker (Render, Fly.io, un VPS, Coolify…)

El `Dockerfile` funciona en cualquier plataforma (trae `pdo_pgsql` y `pdo_mysql`); `railway.json`
se ignora fuera de Railway. Lo que Railway hacía solo, hazlo en la plataforma:

- **Variables de entorno**: en su panel (las mismas del apartado «Variables de entorno»). El contenedor escucha en
  `$PORT` (8080 por defecto).
- **Migraciones**: ejecuta `php scribe migrate` en cada despliegue (con el comando de
  «release/pre-deploy» de la plataforma, si lo tiene, o a mano).
- **Health check**: apunta a `/health`.

### Hosting PHP tradicional (Apache o Nginx + PHP 8.4)

1. Sube el proyecto y ejecuta `composer install --no-dev --optimize-autoloader`.
2. La **raíz web** debe ser la carpeta `public/` (trae su `.htaccess` para Apache), nunca la raíz
   del proyecto: así `.env`, `core/` y `vendor/` no quedan accesibles desde internet.
3. Crea el `.env` en el servidor con `APP_ENV=production` y tus credenciales.
4. Ejecuta `php point migrate` en cada despliegue.
5. Si usas tareas programadas, añade un cron que ejecute el scheduler cada minuto:

```
* * * * * cd /ruta/a/tareas && php scheduler run >> /dev/null 2>&1
```

6. Si usas jobs con `QUEUE_SYNC=false`, deja `php point work` corriendo como servicio
   (systemd, supervisor…).

---

**Flujo de trabajo:** desarrollas en local (`php point serve 8090`, `php point test`), haces commit
y `git push`, y Railway despliega solo cada push a `main`.
