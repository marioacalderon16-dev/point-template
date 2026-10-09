# Point Framework

Plantilla mínima para crear APIs REST en PHP: un core pequeño (`core/`), un ejecutable de línea de
comandos (`point`) y todo lo necesario para pasar de cero a una API desplegada, con base de datos,
autenticación, tests, CI y despliegue con Docker.

```php
<?php

use Core\DB;
use Core\Endpoint;
use Core\Response;

Endpoint::from(__FILE__)
    ->at('POST /users')
    ->group('public')
    ->expects([
        'name'  => 'required|string|min:2',
        'email' => 'required|email|unique:users,email',
    ])
    ->handle(fn ($input) => Response::created(
        DB::table('users')->insertReturning($input, 'id, name, email')
    ));
```

## Qué incluye

- **Endpoints por archivo** con validación declarativa (`expects` y reglas reutilizables en
  `services/Rules.php`) y grupos de middleware (`public`, `protected` con JWT, `admin`/`staff` por rol).
  Referencia de todos los métodos: [docs/REFERENCIA_ENDPOINT.md](docs/REFERENCIA_ENDPOINT.md).
- **Base de datos** Postgres (Supabase, Neon…) o MySQL: query builder, migraciones y seeds.
- **Autenticación JWT**, rate limiting, CORS y cabeceras de seguridad.
- **Tests** con `php point test` (levanta su propio servidor; base aparte con `.env.testing`).
- **Jobs en cola**, tareas programadas (`scheduler`) y plugins.
- **Despliegue**: Dockerfile (Apache + PHP 8.5), Railway con migraciones automáticas y CI de
  GitHub Actions con un Postgres desechable.

## Requisitos

Lo único obligatorio:

- PHP 8.4 o superior y [Composer](https://getcomposer.org/)
- Una base de datos **Postgres o MySQL/MariaDB**, la que sea (local o en la nube)

Se puede usar libremente en cualquier proyecto, también comercial (licencia [MIT](LICENSE)).

### Todo lo demás es opcional

La guía usa algunos servicios como ejemplo, pero ninguno es necesario:

| Sugerencia | Sin ella… |
|---|---|
| Neon / Supabase | Cualquier otro Postgres o MySQL, local o en la nube |
| Railway | Cualquier plataforma con Docker o un hosting PHP tradicional. Las migraciones y el cron del scheduler se configuran a mano ([ver guía](docs/GUIA_INICIO.md#sin-railway-supabase-ni-neon)) |
| GitHub + CI | Funciona igual; solo se pierde la ejecución automática de tests en cada push |
| `.env.testing` | Los tests usan la base de desarrollo (con un aviso) |

### Límites

- Pensado para **APIs REST**; no es para webs con plantillas HTML.
- **SQLite no está soportado.**
- El login no viene hecho: se construye siguiendo la guía (paso 13).
- Antes de subir a una versión futura de PHP (8.6+), comprueba que no haya avisos de
  obsolescencia: el manejador de errores los convierte en HTTP 500. El CI lo detecta.

## Inicio rápido

```bash
git clone https://github.com/marioacalderon16-dev/point-template.git
cd point-template && composer install

php point init ../mi-api        # crea tu proyecto a partir de la plantilla
cd ../mi-api && composer install
```

Configura la conexión a tu base de datos en `.env` (el archivo trae ejemplos para Supabase, Neon y
MySQL) y arranca el servidor:

```bash
php point serve 8080
curl http://localhost:8080/health
```

**Guía completa paso a paso** (base de datos, migraciones, endpoints, tests, login con JWT y
despliegue en Railway): [docs/GUIA_INICIO.md](docs/GUIA_INICIO.md).

## Comandos principales

| Comando | Qué hace |
|---|---|
| `php point init <ruta>` | Crea un proyecto nuevo |
| `php point serve [puerto]` | Servidor de desarrollo |
| `php point make:endpoint <ruta>` | Crea un endpoint (`--post`, `--public`, …) |
| `php point make:action <Nombre>` | Crea una acción: lógica reutilizable sin HTTP en `services/Actions/` |
| `php point make:migration <tabla>` | Crea una migración |
| `php point migrate` / `rollback` | Aplica / deshace migraciones |
| `php point make:seed <tabla>` / `seed` | Crea / ejecuta datos iniciales |
| `php point make:test <nombre>` | Crea un archivo de tests |
| `php point test [filtro]` | Ejecuta los tests |
| `php point test --smoke` | Llama a todas las rutas GET con datos de ejemplo y falla si alguna da 5xx |
| `php point make:http` | Genera `requests.http` para probar cada ruta desde VS Code o PhpStorm |
| `php point call <ruta> [campo=valor] [--as=ID]` | Ejecuta un endpoint desde la terminal (scripts, cron), con validación y permisos |
| `php point mcp [--as=ID]` | Las rutas con `->mcp()` como herramientas para asistentes de IA (Claude) |
| `php point logs [-f] [--level=error]` | Logs con colores; `-f` los sigue en vivo |
| `php point routes` | Lista las rutas: grupo, quién puede entrar (public, token, roles) y campos |
| `php point openapi --serve` | Documentación OpenAPI con Swagger UI |
| `php point plugin:list` | Plugins disponibles |

`php point` sin argumentos muestra todos los comandos.

## Licencia

[MIT](LICENSE)
