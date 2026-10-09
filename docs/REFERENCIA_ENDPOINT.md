# Referencia de endpoints

Todo lo que puede hacer un endpoint en una página. Para el recorrido paso a paso, ver
[GUIA_INICIO.md](GUIA_INICIO.md).

```bash
php point make:endpoint posts/update --put   # crea endpoints/posts/update.php con esta estructura
php point routes                             # lista las rutas: grupo, quién puede entrar y campos
php point make:action CreateUser             # lógica reutilizable en services/Actions/CreateUser.php
php point make:http                          # requests.http con una petición de ejemplo por ruta
php point test --smoke                       # llama a todas las rutas GET y falla si alguna da 5xx
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
Una ruta que no existe responde 404; si existe con otro método, 405 con la cabecera `Allow`.

## Métodos

| Método | Para qué | Ejemplo |
|---|---|---|
| `at('MÉTODO /ruta')` | Método HTTP y ruta. `{x}` es un parámetro de ruta. Varios métodos con `\|`. Un formato inválido lanza excepción al cargar | `->at('GET /posts/{id}')`, `->at('PUT\|PATCH /posts/{id}')` |
| `group('nombre')` | Grupo de middlewares de `config/middleware.php`. Un grupo no declarado responde 500 | `->group('public')`, `->group('admin')` |
| `through(...)` | Middlewares extra solo para esta ruta, con parámetros tras `:` | `->through('rate_limit:5')`, `->through('auth:billing')` |
| `expects([...])` | Reglas por campo. Solo los campos declarados llegan a `handle`. Varias llamadas se acumulan | `'email' => Rules::email()` |
| `name('a.b')` | Nombre de la ruta, necesario para `connectTo()` | `->name('posts.show')` |
| `guard(fn)` | Comprobación extra antes de validar. Recibe el input **sin validar** (con `_user`); `false` responde 403 | `->guard(fn ($in) => Auth::hasRole($in, 'admin') \|\| $in['id'] == Auth::id($in))` |
| `uses(Clase::class)` | Inyecta servicios como argumentos extra de `handle` | `->uses(Mailer::class)->handle(fn ($in, Mailer $m) => ...)` |
| `cache(60)` | Guarda la respuesta GET esos segundos. La clave incluye ruta, campos validados y usuario; solo guarda respuestas 2xx. Cabecera `X-Cache: HIT/MISS` | `->at('GET /stats')->cache(60)` |
| `transform(fn)` | Modifica la respuesta de `handle` antes de enviarla | `->transform(fn ($res) => $res + ['version' => 1])` |
| `onError(fn)` | Respuesta propia si algo lanza una excepción | `->onError(fn (Throwable $e) => Response::error('No disponible', 503))` |
| `connectTo('nombre')` | Encadena otro endpoint por nombre: la respuesta de este es el input del siguiente y solo se devuelve la del último (para juntar datos, ver la receta «Una pantalla, una petición») | `->connectTo('posts.notify')` |
| `when(fn)` | Tras `connectTo`: solo encadena si devuelve `true` (recibe la respuesta) | `->connectTo('audit.log')->when(fn ($res) => $res['status'] === 201)` |
| `handle(fn)` | La lógica. También acepta `[Clase::class, 'método']` | `->handle([PostController::class, 'update'])` |

### `$input`

`$input` reúne el cuerpo (JSON o formulario), la query string y los parámetros de ruta. Con
`expects()` solo llegan los campos declarados, ya convertidos (`integer` → int, `boolean` → bool,
`trim`, `lowercase`…). En rutas con token añade también los datos del usuario, que se leen con `Auth`.

Las claves que empiezan por `_` están reservadas para los middlewares: si el cliente las envía, se
descartan. **Una ruta POST/PUT/PATCH sin `expects` recibe cualquier campo**, y
`DB::table(...)->insert($input)` guardaría también lo que no esperas (`role`, `is_admin`…).
`php point routes` marca esas rutas con `NO-EXPECTS`.

## Lógica reutilizable: acciones

La lógica del negocio va en una acción (`php point make:action CreateUser` crea
`services/Actions/CreateUser.php`). El endpoint se queda con lo HTTP y la acción se puede llamar
desde cualquier sitio:

```php
// endpoint
->handle(fn ($in) => Response::created((new CreateUser)($in)))

// job, seed, otro endpoint o test
(new CreateUser)(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'secreto123']);
```

La acción declara sus datos en `rules()`, así se validan igual desde el endpoint y desde fuera de HTTP:

```php
->expects(CreateUser::rules())                                   // endpoint
$v = new Core\Validator($fila, CreateUser::rules());             // job o importación
if ($v->passes()) (new CreateUser)($v->validated());
```

Regla: la acción **no sabe nada de HTTP**. No usa `Response::` (devuelve datos), no lee `$_GET` ni
`$input['_user']`. Si necesita al usuario, se le pasa: `(new CreatePost)($in, authorId: Auth::id($in))`.
Si tiene dependencias en el constructor, se resuelven con `service(CreateUser::class)` o `->uses()`.

## Receta: una pantalla, una petición

Cuando una pantalla del frontend necesita datos de varias rutas (`/me`, `/posts`, `/stats`), un
endpoint agregador los junta en una sola respuesta llamando a las **mismas acciones** que usan esas
rutas. No duplica lógica y ahorra viajes de red:

```php
// endpoints/dashboard.php
Endpoint::from(__FILE__)->at('GET /dashboard')->group('protected')->cache(30)
    ->handle(fn ($in) => Response::ok([
        'me'    => (new GetUser)(Auth::id($in)),
        'posts' => (new ListPosts)(['limit' => 5]),
        'stats' => (new GetStats)(),
    ]));
```

- Los permisos son los del endpoint agregador. Si una parte exige un rol, compruébalo aquí
  (`->group('admin')` o `Auth::hasRole($in, 'admin')`).
- `->cache(30)` guarda el panel completo, por usuario.
- **No uses `connectTo()` para esto.** Es una cadena: cada endpoint recibe la salida del anterior,
  filtrada por su `expects`, y solo llega la respuesta del último. Sirve para flujos («crear el
  pedido → notificar»), no para juntar datos.

```
connectTo:  A ──► B ──► C ──► (solo C)
agregador:  ┌► acción A ─┐
            ├► acción B ─┼──► { a, b, c }
            └► acción C ─┘
```

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

Los tokens llevan `iss` y `aud` (`JWT_ISSUER`/`JWT_AUDIENCE` en `.env`, por defecto `point`/`point-api`)
y solo se aceptan los que coinciden: un JWT de otro sistema que comparta el secreto no vale.

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

`mime:jpg,png` comprueba la extensión y, para los tipos conocidos, también el contenido real del
archivo. `$file->store('avatars', $nombre)` guarda siempre dentro de `storage/uploads`.
