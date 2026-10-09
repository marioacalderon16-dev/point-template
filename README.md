# Point Framework

Plantilla mínima para crear APIs REST en PHP: un core pequeño (`core/`), un ejecutable de línea de
comandos (`point`) y todo lo necesario para pasar de cero a una API desplegada, con base de datos,
autenticación, tests, CI y despliegue con Docker.

```php
<?php

use Core\DB;
use Core\Endpoint;

Endpoint::from(__FILE__)
    ->at('POST /users')
    ->group('public')
    ->expects([
        'name'  => 'required|string|min:2',
        'email' => 'required|email|unique:users,email',
    ])
    ->handle(fn ($input) => [
        'status' => 201,
        'data'   => DB::table('users')->insertReturning($input, 'id, name, email'),
    ]);
```

## Qué incluye

- **Endpoints por archivo** con validación declarativa (`expects`) y grupos de middleware
  (`public`, `protected` con JWT, `admin` por rol).
- **Base de datos** Postgres (Supabase, Neon…) o MySQL: query builder, migraciones y seeds.
- **Autenticación JWT**, rate limiting, CORS y cabeceras de seguridad.
- **Tests** con `php point test` (levanta su propio servidor; base aparte con `.env.testing`).
- **Jobs en cola**, tareas programadas (`scheduler`) y plugins.
- **Despliegue**: Dockerfile (Apache + PHP 8.5), Railway con migraciones automáticas y CI de
  GitHub Actions con un Postgres desechable.

## Requisitos

- PHP 8.4 o superior con `pdo_pgsql` (o `pdo_mysql`)
- [Composer](https://getcomposer.org/)
- Una base de datos Postgres o MySQL (local o en la nube)

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
| `php point make:migration <tabla>` | Crea una migración |
| `php point migrate` / `rollback` | Aplica / deshace migraciones |
| `php point make:seed <tabla>` / `seed` | Crea / ejecuta datos iniciales |
| `php point make:test <nombre>` | Crea un archivo de tests |
| `php point test [filtro]` | Ejecuta los tests |
| `php point routes` | Lista las rutas registradas |
| `php point openapi --serve` | Documentación OpenAPI con Swagger UI |
| `php point plugin:list` | Plugins disponibles |

`php point` sin argumentos muestra todos los comandos.

## Licencia

[MIT](LICENSE)
