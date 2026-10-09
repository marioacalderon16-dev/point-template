# Referencia de endpoints

Todo lo que puede hacer un endpoint en una página. Para el recorrido paso a paso, ver
[GUIA_INICIO.md](GUIA_INICIO.md).

```bash
php point make:endpoint posts/update --put   # crea endpoints/posts/update.php con esta estructura
php point routes                             # lista las rutas: grupo, quién puede entrar y campos
```

## Estructura

```php
<?php

use App\Services\Rules;
use Core\Auth;
use Core\Endpoint;
use Core\Response;

Endpoint::from(__FILE__)                 // obligatorio, siempre igual
    ->at('PUT /posts/{id}')              // método y ruta
    ->name('posts.update')               // opcional: nombre para connectTo() y OpenAPI
    ->group('staff')                     // quién puede entrar (config/middleware.php)
    ->expects([                          // validación: si falla responde 422 y no ejecuta handle
        'id'    => Rules::id(),
        'title' => Rules::text(max: 200),
    ])
    ->handle(function (array $input) {   // la lógica; $input ya está validado
        return Response::ok(['id' => $input['id'], 'by' => Auth::id($input)]);
    });
```

Orden en que se ejecuta una petición:
1. Middlewares del grupo y de `through()`. Sin token responde 401 y con un rol no permitido 403.
2. `guard()`. Si devuelve `false` responde 403.
3. `expects()`. Con datos inválidos responde 422.
4. `handle()`.
5. `connectTo()`.
6. `transform()`.
7. Se envía la respuesta.

Una excepción en los pasos 4 a 6 la recoge `onError()` si está definido; si no, responde 500.

## Métodos

| Método | Para qué | Ejemplo |
|---|---|---|
| `at('MÉTODO /ruta')` | Método HTTP y ruta. `{x}` es un parámetro de ruta. Varios métodos con `\|` | `->at('GET /posts/{id}')`, `->at('PUT\|PATCH /posts/{id}')` |
| `group('nombre')` | Grupo de middlewares de `config/middleware.php`. Un grupo no declarado responde 500 | `->group('public')`, `->group('admin')` |
| `through(...)` | Middlewares extra solo para esta ruta, con parámetros tras `:` | `->through('rate_limit:5')`, `->through('auth:billing')` |
| `expects([...])` | Reglas por campo. Solo los campos declarados llegan a `handle` | `'email' => Rules::email()` |
| `name('a.b')` | Nombre de la ruta, necesario para `connectTo()` | `->name('posts.show')` |
| `guard(fn)` | Comprobación extra antes de validar. Recibe el input **sin validar** (con `_user`); `false` responde 403 | `->guard(fn ($in) => Auth::hasRole($in, 'admin') \|\| $in['id'] == Auth::id($in))` |
| `uses(Clase::class)` | Inyecta servicios como argumentos extra de `handle` | `->uses(Mailer::class)->handle(fn ($in, Mailer $m) => ...)` |
| `transform(fn)` | Modifica la respuesta de `handle` antes de enviarla | `->transform(fn ($res) => $res + ['version' => 1])` |
| `onError(fn)` | Respuesta propia si algo lanza una excepción | `->onError(fn (Throwable $e) => Response::error('No disponible', 503))` |
| `connectTo('nombre')` | Encadena otro endpoint por nombre: la respuesta de este es el input del siguiente | `->connectTo('posts.notify')` |
| `when(fn)` | Tras `connectTo`: solo encadena si devuelve `true` (recibe la respuesta) | `->connectTo('audit.log')->when(fn ($res) => $res['status'] === 201)` |
| `handle(fn)` | La lógica. También acepta `[Clase::class, 'método']` | `->handle([PostController::class, 'update'])` |

### `$input`

`$input` reúne el cuerpo (JSON o formulario), la query string y los parámetros de ruta. Con
`expects()` solo llegan los campos declarados, ya convertidos (`integer` → int, `boolean` → bool,
`trim`, `lowercase`…). En rutas con token añade también los datos del usuario, que se leen con `Auth`.

## Recursos CRUD en un archivo

```php
Endpoint::resource('/posts')
    ->group('staff')
    ->expects(['title' => Rules::text(max: 200)])   // se aplica a create y update
    ->list(fn ($in) => Response::ok(DB::table('posts')->get()))                       // GET    /posts
    ->show(fn ($in) => Response::ok(DB::table('posts')->where('id', $in['id'])->first())) // GET    /posts/{id}
    ->create(fn ($in) => Response::created(DB::table('posts')->insertReturning($in, 'id, title'))) // POST /posts
    ->update(fn ($in) => Response::ok(...))                                           // PUT|PATCH /posts/{id}
    ->delete(fn ($in) => Response::noContent());                                      // DELETE /posts/{id}
```

Nombres generados: `posts.list`, `posts.show`, `posts.create`, `posts.update` y `posts.delete`.
`Resource` no admite `uses()`.

## Respuestas (`Core\Response`)

| Método | Código | Cuerpo |
|---|---|---|
| `Response::ok($data, 'mensaje')` | 200 | `{status, message, data}` |
| `Response::created($data)` | 201 | `{status, message, data}` |
| `Response::noContent()` | 204 | sin cuerpo |
| `Response::error('mensaje', 409, $errors)` | el indicado (400 por defecto) | `{status, message, errors?}` |
| `Response::notFound()` | 404 | `{status, message}` |
| `Response::forbidden()` | 403 | `{status, message}` |
| `Response::paginated($result)` | 200 | `{status, data, meta}` |

Un array con `'status' => 201` también funciona, pero `Response::` es más legible y uniforme.

## Usuario autenticado (`Core\Auth`)

Solo en rutas con token (grupos `protected`, `authenticated`, `admin`, `staff`… o `through('auth')`).
En una ruta sin token lanzan un error (500) en lugar de devolver `null`.

| Método | Devuelve |
|---|---|
| `Auth::id($input)` | ID del usuario (claim `sub`) |
| `Auth::user($input)` | Todos los claims del token |
| `Auth::roles($input)` | Lista de roles (`role` y `roles` del token) |
| `Auth::hasRole($input, 'admin', 'editor')` | `true` si tiene al menos uno |
| `Auth::issue(['sub' => 1, 'role' => 'editor'])` | Emite un token (login) |

## Validación

Reglas nativas: `required`, `optional`, `default:x`, `string`, `integer`, `numeric`, `boolean`,
`array`, `email`, `url`, `uuid`, `ip`, `date[:formato]`, `json`, `alpha_num`, `regex:/.../`,
`min:n`, `max:n`, `between:a,b`, `in:a,b`, `not_in:a,b`, `list_in:a,b`, `confirmed`,
`unique:tabla,columna`, `exists:tabla,columna`, `each:regla`, `file`, `max_size:kb`, `mime:jpg,png`,
`trim`, `lowercase`, `uppercase`, `strip_tags` y `source:body,query,route`.

Comparación con otro campo o con un literal: `gt`, `gte`, `lt` y `lte` (números) y `after`,
`after_or_equal`, `before` y `before_or_equal` (fechas). Por ejemplo, `'to' => 'required|date|after_or_equal:from'`.

Atajos de `App\Services\Rules`: `id()`, `uuid()`, `status(Enum::class | [...])`, `email()`,
`text(max, min)`, `boolean()`, `date()` y `...pagination()`. Todos admiten `required: false`.
Las reglas propias se definen en `Rules::custom()`.

Una regla que no existe (errata) responde 500 en lugar de dejar el campo sin validar.
