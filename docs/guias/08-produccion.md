# Guía 8: a producción

**Tiempo:** 30 minutos · **Requisito previo:** [Guía 7](07-rendimiento-y-segundo-plano.md)

**Tareas** está completa. En esta última guía la publicamos en internet con todo funcionando: la API,
las migraciones automáticas, el worker de la cola, el scheduler y los tests en cada cambio. Al final, la
conectamos a un **asistente de IA** para consultarla conversando.

**Lo que aprenderás:**
- Preparar el proyecto para producción.
- Ejecutar los tests automáticamente en GitHub (CI).
- Desplegar con Docker en Railway (u otra plataforma) con migraciones automáticas.
- Dejar en marcha el worker y el scheduler.
- Usar tu API desde Claude con `point mcp`.

---

## Paso 1: antes de publicar

Comprueba esta lista en tu proyecto:

```bash
php point test              # todos los tests en verde
php point test --smoke      # ninguna ruta da 5xx
git status --short          # .env no debe aparecer
php point routes            # revisa la columna ACCESS: ¿alguna ruta "public" que no debería serlo?
```

En producción, **algunas variables cambian** (las pondremos en la plataforma, nunca en el repositorio):

| Variable | En producción | Por qué |
|---|---|---|
| `APP_ENV` | `production` | Oculta los detalles de los errores (archivo, línea, traza) |
| `JWT_SECRET` | Uno **nuevo** | Si alguien tuviera el de desarrollo, podría crear tokens válidos |
| `DB_DSN`, `DB_USER`, `DB_PASS` | La base de producción | Nunca la misma que la de desarrollo |
| `CORS_ORIGINS` | La dirección de tu frontend | Sin ella, los navegadores no dejan que otra web llame a tu API |
| `WORKER_ENABLED` | `true` | Arranca el worker de la cola (guía 7) |
| `QUEUE_SYNC` | `false` | Los jobs van a la cola en lugar de ejecutarse al momento |

Genera un `JWT_SECRET` nuevo con:

```bash
php -r 'echo bin2hex(random_bytes(32)), "\n";'
```

## Paso 2: el repositorio en GitHub

1. En github.com → **New repository** → privado, **sin** README ni `.gitignore`.
2. Súbelo (con la [CLI de GitHub](https://cli.github.com/) autenticada):

```bash
gh repo create tareas --private --source . --push
```

O a mano: `git remote add origin https://github.com/TU_USUARIO/tareas.git && git push -u origin main`.

Comprueba en GitHub que **no** está `.env` (solo `.env.example`).

## Paso 3: tests automáticos en cada cambio (CI)

`point init` ya creó `.github/workflows/ci.yml`. En cada `git push`, GitHub Actions:

1. Levanta un **Postgres desechable**, solo para esa ejecución.
2. Instala las dependencias y revisa que no tengan vulnerabilidades conocidas (`composer audit`).
3. Analiza el código con PHPStan (`composer stan`).
4. Aplica tus migraciones (`php point migrate`) y ejecuta tus tests (`php point test`).

Mira el resultado en la pestaña **Actions** de tu repositorio: un tick verde significa que todo pasó.
Si quieres, añade la prueba de humo al final del archivo, con la misma sangría que los otros pasos:

```yaml
      - run: php point test --smoke
```

Si usas MySQL, el archivo trae comentado un servicio MySQL listo para sustituir al de Postgres.

## Paso 4: entender la imagen Docker

La plantilla trae un `Dockerfile` que funciona en cualquier plataforma con Docker:

- **Apache + PHP 8.5** con los drivers de Postgres y MySQL, y la configuración de producción de PHP.
- La raíz web es `public/`: `.env`, `core/` y `vendor/` no son accesibles desde internet.
- `.dockerignore` deja fuera de la imagen `.env`, `.git`, `tests/` y las dependencias de desarrollo.
- Al arrancar, `docker/entrypoint.sh` ejecuta el **scheduler** cada minuto (salvo `SCHEDULER_ENABLED=false`) y, con `WORKER_ENABLED=true`, el **worker** de la cola.

> **Importante:** la cola de jobs, la caché y los adjuntos se guardan como archivos en `storage/`. Por eso
> el worker corre **en el mismo contenedor** que la API: un segundo servicio tendría su propio disco y
> no vería los jobs. Por la misma razón, usa **una sola instancia** de la aplicación.

Si tienes Docker instalado, puedes probarla en local:

```bash
docker build -t tareas .
docker run --rm -p 8080:8080 -e APP_ENV=production -e WORKER_ENABLED=true \
  -e JWT_SECRET=tu-secreto-de-64-caracteres -e DB_DSN="pgsql:host=host.docker.internal;port=5432;dbname=tareas" \
  -e DB_USER=tu-usuario -e DB_PASS= tareas
curl -s http://localhost:8080/health
```

## Paso 5: desplegar en Railway

1. railway.com → **New Project → Deploy from GitHub repo** → elige `tareas` (autoriza la app de Railway
   solo para ese repositorio). El primer despliegue falla: aún no hay variables.
2. Servicio → **Variables → Raw Editor**:

```
APP_ENV=production
TIME_ZONE=America/Bogota
LOG_LEVEL=info
DB_DSN=pgsql:host=HOST;port=5432;dbname=neondb;sslmode=require
DB_USER=neondb_owner
DB_PASS=tu-contraseña
JWT_SECRET=el-nuevo-del-paso-1
JWT_TTL=3600
WORKER_ENABLED=true
QUEUE_SYNC=false
CORS_ORIGINS=https://tu-frontend.com
```

3. **Settings → Networking → Generate Domain** (puerto `8080` si lo pide). Al guardar, Railway vuelve a
   desplegar; cuando el servicio está en verde, `/health` respondió.
4. **Un volumen para `storage/`.** Servicio → **Settings → Volumes → Add Volume**, con la ruta
   `/var/www/html/storage`. Sin él, los adjuntos, la cola y la caché se pierden en cada despliegue.

**Migraciones automáticas.** `railway.json` ejecuta `php /var/www/html/scribe migrate` **antes** de
arrancar cada versión nueva. Si una migración falla, el despliegue se detiene y sigue funcionando la
versión anterior. Mientras tanto, la versión anterior atiende el tráfico, así que escribe migraciones
compatibles con ella: añade columnas `nullable` y no renombres ni borres columnas en el mismo despliegue
que deja de usarlas.

**Flujo de trabajo:** a partir de ahora, cada `git push` a `main` pasa la CI y Railway despliega solo.

## Paso 6: el primer administrador

La base de producción empieza vacía (el seed de la guía 6 es para desarrollo). Regístrate con
`POST /users` y date el rol desde la consola SQL de tu base de datos:

```sql
UPDATE users SET role = 'admin' WHERE email = 'tu@email.com';
```

Vuelve a hacer login para recibir un token con el rol.

## Paso 7: probar en producción

```bash
URL=https://tareas-production.up.railway.app      # tu dominio de Railway
curl -s $URL/health
curl -s -X POST $URL/login -H "Content-Type: application/json" -d '{"email":"tu@email.com","password":"tu-contraseña"}'
curl -s $URL/dashboard -H "Authorization: Bearer TOKEN"
curl -s $URL/me -H "Authorization: Bearer token-falso"          # 401
curl -s -i $URL/health | head -20                               # cabeceras de seguridad; sin versión de PHP ni Apache
```

En producción, un error 500 no muestra archivo, línea ni traza: el detalle queda en los logs de Railway.

## Paso 8: sin Railway, Supabase ni Neon

Ninguno es obligatorio: Point necesita una base de datos Postgres o MySQL y un sitio donde ejecutar
PHP 8.4 o superior.

**Base de datos.** Cualquier Postgres o MySQL/MariaDB sirve (local, servidor propio, AWS RDS,
DigitalOcean…): solo cambian `DB_DSN`, `DB_USER` y `DB_PASS`.

**Otra plataforma con Docker (Render, Fly.io, un VPS, Coolify…).** El mismo `Dockerfile` funciona;
`railway.json` se ignora. Haz en la plataforma lo que Railway hacía solo:
- Las variables del paso 5, en su panel. El contenedor escucha en `$PORT` (8080 por defecto).
- Las migraciones: `php scribe migrate` en cada despliegue (con su comando de *release* o *pre-deploy*, si lo tiene).
- El *health check*, apuntando a `/health`.
- Un volumen persistente en `/var/www/html/storage` y una sola instancia.

**Hosting PHP tradicional (Apache o Nginx + PHP 8.4).**
1. Sube el proyecto y ejecuta `composer install --no-dev --optimize-autoloader`.
2. La **raíz web** debe ser la carpeta `public/` (trae su `.htaccess` para Apache), nunca la raíz del proyecto.
3. Crea el `.env` en el servidor con `APP_ENV=production` y tus credenciales.
4. Ejecuta `php point migrate` en cada despliegue.
5. Añade el scheduler al cron, una sola línea:

```
* * * * * cd /ruta/a/tareas && php scheduler run >> /dev/null 2>&1
```

6. Deja `php point work` corriendo como servicio (systemd, supervisor…) en el **mismo servidor**.

## Paso 9: tu API en un asistente de IA

Con `point mcp`, un asistente como Claude puede usar los endpoints que tú elijas. Basta con marcarlos con
`->mcp('descripción')`: la descripción le explica **cuándo** usarlo, y los campos salen de `expects`.

Marca el panel:

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
    ->mcp('Resumen del usuario: número de proyectos, tareas por estado y tareas vencidas.')
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

Y el alta de tareas:

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
    ->mcp('Crea una tarea en un proyecto. Prioridad de 1 a 5; fechas en formato AAAA-MM-DD, la entrega nunca en fin de semana.')
    ->expects(CreateTask::rules())
    ->handle(fn ($input) => Response::created((new CreateTask)($input, Auth::id($input))));
```

Conéctalo a Claude Code, desde la carpeta del proyecto (en tu ordenador, con tu base de desarrollo):

```bash
claude mcp add tareas -- php point mcp --as=1
```

En Claude Desktop, añade a su archivo de configuración:

```json
{ "mcpServers": { "tareas": { "command": "php", "args": ["/ruta/a/tareas/point", "mcp", "--as=1"] } } }
```

Y pregúntale en lenguaje natural:

> **Tú:** ¿Cómo voy? ¿Tengo tareas vencidas?
>
> **Claude** usa `dashboard` y responde: Tienes 1 proyecto y 3 tareas: 1 terminada, 1 en curso y 1 abierta. Ninguna vencida.
>
> **Tú:** Crea una tarea "Preparar la demo" en el proyecto 1 para el sábado 5 de enero de 2030.
>
> **Claude** usa `tasks_create`, recibe el error «El campo due_date no puede caer en fin de semana» y te propone el lunes 7.

- `--as=1` decide **con qué usuario** actúa la IA: solo puede hacer lo que ese usuario puede hacer por HTTP.
- Antes de crear o modificar algo, el asistente te pide confirmación.
- Tus reglas de validación también **guían a la IA**: los errores le explican qué corregir.
- Expón solo lo necesario, y con cuidado los textos libres escritos por otros usuarios: podrían intentar dar órdenes a la IA.

## Paso 10: guardar y publicar

```bash
git add . && git commit -m "Guía 8: listo para producción y asistente de IA"
git push
```

---

## Resumen

- En producción cambian `APP_ENV`, `JWT_SECRET`, la base de datos y `CORS_ORIGINS`.
- La CI de GitHub ejecuta auditoría, PHPStan, migraciones y tests en cada `push`.
- El `Dockerfile` sirve en cualquier plataforma; Railway aplica las migraciones antes de cada versión.
- El worker (`WORKER_ENABLED=true`) y el scheduler corren en el mismo contenedor que la API, con un volumen en `storage/`.
- `->mcp()` y `php point mcp` dejan que un asistente de IA use tu API, con tus permisos y tus reglas.

**¡Enhorabuena!** Has construido una API completa con Point. Para consultar cualquier función, usa la
[referencia](../REFERENCIA_ENDPOINT.md); para volver a la lista de guías, el [índice](../GUIA_INICIO.md).
