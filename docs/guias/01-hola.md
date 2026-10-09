# Guía 1: Hola, Point

**Tiempo:** 10 minutos · **Requisito previo:** ninguno

En esta serie construimos paso a paso **Tareas**, una API para gestionar proyectos y tareas. Cada guía
añade algo al mismo proyecto. En esta primera creamos el proyecto y nuestro primer endpoint, sin base
de datos todavía.

**Lo que aprenderás:**
- Crear un proyecto nuevo con Point y arrancarlo.
- Qué es un endpoint y cómo se escribe.
- Probarlo desde el navegador, con `curl` y desde la terminal con `point call`.

**Necesitas:** PHP 8.4 o superior, [Composer](https://getcomposer.org/) y git.

---

## Paso 1: crear el proyecto

Desde la carpeta de la plantilla de Point:

```bash
cd /ruta/a/point-template
php point init ../tareas
```

`init` crea la carpeta `tareas` con todo lo necesario: el núcleo de Point (`core/`), la configuración,
el endpoint `/health`, el `Dockerfile` y un archivo `.env` con un `JWT_SECRET` aleatorio (lo usaremos
en la guía 3).

## Paso 2: instalar las dependencias

```bash
cd ../tareas
composer install
```

Si ejecutas cualquier `php point …` antes de este paso, verás «Ejecuta 'composer install' primero».

## Paso 3: arrancar el servidor

```bash
php point serve 8090
```

Deja esta terminal abierta: es tu servidor de desarrollo. Si ves `Address already in use`, otro
programa usa ese puerto; prueba con otro número (`php point serve 8091`).

Abre **otra terminal** en la carpeta `tareas` y comprueba que responde:

```bash
curl -s http://localhost:8090/health
```

Verás un JSON con `"status"`. Que diga `degraded` es normal ahora: todavía no hay base de datos (la
configuramos en la guía 2).

## Paso 4: crear tu primer endpoint

Un **endpoint** es una dirección de tu API (por ejemplo `GET /hola`) más el código que responde. En
Point, cada endpoint vive en un archivo dentro de `endpoints/`. Créalo con:

```bash
php point make:endpoint hola --public
```

`--public` significa que cualquiera puede llamarlo, sin iniciar sesión (sin él, Point lo protegería con
token, que veremos en la guía 3).

Abre `endpoints/hola.php` y reemplaza su contenido por:

```php file=endpoints/hola.php
<?php

use Core\Endpoint;
use Core\Response;

Endpoint::from(__FILE__)
    ->at('GET /hola')
    ->name('hola')
    ->group('public')
    ->expects([
        'nombre' => 'optional|string|max:50',
    ])
    ->handle(function ($input) {
        $nombre = $input['nombre'] ?? 'mundo';

        return Response::ok(['saludo' => "Hola, {$nombre}"]);
    });
```

Línea por línea:

| Línea | Qué hace |
|---|---|
| `Endpoint::from(__FILE__)` | Empieza a definir un endpoint. Siempre igual |
| `->at('GET /hola')` | El método HTTP (`GET`) y la ruta (`/hola`) |
| `->name('hola')` | Un nombre para referirnos a él (lo usaremos con `point call`) |
| `->group('public')` | Quién puede entrar: `public` = cualquiera |
| `->expects([...])` | Qué datos acepta y cómo se validan: `nombre` es opcional, texto de 50 caracteres como máximo |
| `->handle(function ($input) {...})` | El código que responde. `$input` trae los datos ya validados |
| `Response::ok([...])` | Responde con código 200 y los datos en `data` |

## Paso 5: probarlo

No hace falta reiniciar el servidor: Point lee los cambios en cada petición.

```bash
curl -s http://localhost:8090/hola
```

```json
{
    "status": 200,
    "message": "OK",
    "data": {
        "saludo": "Hola, mundo"
    }
}
```

Con un nombre (en la URL, como *query string*):

```bash
curl -s "http://localhost:8090/hola?nombre=Ana"
```

Responde `"saludo": "Hola, Ana"`. Y si el nombre es demasiado largo, la validación lo rechaza **antes**
de ejecutar tu código:

```bash
curl -s "http://localhost:8090/hola?nombre=$(printf 'a%.0s' {1..60})"
```

Responde **422** con `"errors": {"nombre": ["El campo nombre no debe exceder 50 caracteres."]}`.

También puedes abrir `http://localhost:8090/hola?nombre=Ana` en el navegador.

## Paso 6: probarlo desde la terminal, sin servidor

`point call` ejecuta un endpoint directamente, por su nombre. Es útil para scripts y para probar rápido:

```bash
php point call hola
php point call hola nombre=Luis
```

## Paso 7: ver las rutas de tu API

```bash
php point routes
```

```
  METHOD  PATH     NAME    GROUP   ACCESS  FIELDS
  GET     /health  health  -       public  -
  GET     /hola    hola    public  public  nombre
```

`ACCESS` indica quién puede llamar a cada ruta y `FIELDS`, qué datos acepta.

## Paso 8: guardar el progreso con git

```bash
git init
git status --short | grep env        # debe salir solo .env.example, nunca .env
git add . && git commit -m "Guía 1: proyecto Tareas y GET /hola"
```

El archivo `.env` guarda secretos y git lo ignora a propósito: **nunca lo subas** a un repositorio.

---

## Resumen

- `php point init` crea el proyecto y `php point serve` lo arranca.
- Un endpoint es un archivo en `endpoints/` con su ruta (`at`), quién entra (`group`), qué acepta
  (`expects`) y qué responde (`handle`).
- Si los datos no cumplen `expects`, Point responde 422 sin ejecutar tu código.
- `php point routes` lista las rutas y `php point call` las ejecuta desde la terminal.

**Siguiente:** [Guía 2: base de datos y primera tabla](02-base-de-datos.md), donde guardamos proyectos
en una base de datos real.
