# Referencia de Point

Todo lo que puede hacer un endpoint y todos los comandos de `point`, con ejemplos. Para el
recorrido paso a paso (de proyecto vacío a despliegue), ver [GUIA_INICIO.md](GUIA_INICIO.md).

## Índice

1. [Crear un endpoint](#1-crear-un-endpoint)
   - [Estructura](#estructura) · [Orden de ejecución](#orden-de-ejecución) · [Métodos](#métodos) · [`$input`](#input)
2. [Datos y validación](#2-datos-y-validación)
   - [Reglas nativas](#reglas-nativas) · [Atajos `Rules`](#atajos-rules) · [Comparar campos](#comparar-campos) · [Máquina de estados](#máquina-de-estados-rulestransition) · [Reglas propias](#reglas-propias) · [Archivos](#archivos)
3. [Respuestas](#3-respuestas)
4. [Usuario y permisos](#4-usuario-y-permisos)
   - [Grupos y roles](#grupos-y-roles) · [`Auth`](#auth) · [`guard`](#guard)
5. [Rendimiento y fiabilidad](#5-rendimiento-y-fiabilidad)
   - [Caché](#caché-cache) · [Idempotencia](#idempotencia-idempotent) · [Asíncrono a demanda](#asíncrono-a-demanda-asyncable--async) · [Límite de peticiones](#límite-de-peticiones)
6. [Organizar la lógica](#6-organizar-la-lógica)
   - [Acciones](#acciones) · [CRUD en un archivo](#crud-en-un-archivo-endpointresource) · [Una pantalla, una petición](#receta-una-pantalla-una-petición) · [Encadenar endpoints](#encadenar-endpoints-connectto) · [Servicios](#servicios-uses)
7. [Herramientas de terminal](#7-herramientas-de-terminal)
   - [Ver y probar rutas](#ver-y-probar-rutas) · [`point call`](#point-call) · [`point mcp`](#point-mcp-asistentes-de-ia) · [Logs](#logs) · [Documentación (Swagger)](#documentación-swagger) · [Todos los comandos](#todos-los-comandos)

---

## 1. Crear un endpoint

```bash
php point make:endpoint posts/update --put      # crea endpoints/posts/update.php
```

Opciones: `--post`, `--put`, `--patch` o `--delete` (por defecto GET); `--group=nombre` (por defecto `protected`);
`--public` (atajo de `--group=public`). Un segmento `:id` se convierte en `{id}`: `make:endpoint posts/:id`.

### Estructura

```php
<?php

use App\Services\Rules;
use Core\Auth;
use Core\Endpoint;
use Core\Response;

Endpoint::from(__FILE__)                 // obligatorio, siempre igual
    ->at('PUT /posts/{id}')              // método y ruta
    ->name('posts.update')               // opcional: nombre para connectTo(), point call y OpenAPI
    ->group('staff')                     // quién puede entrar (config/middleware.php)
    ->expects([                          // validación: si falla responde 422 y no ejecuta handle
        'id'    => Rules::id(),
        'title' => Rules::text(max: 200),
    ])
    ->handle(function (array $input) {   // la lógica; $input ya está validado
        return Response::ok(['id' => $input['id'], 'by' => Auth::id($input)]);
    });
```

### Orden de ejecución

1. Middlewares del grupo y de `through()`. Sin token responde 401 y con un rol no permitido, 403.
2. `guard()`. Si devuelve `false`, responde 403.
3. `expects()`. Con datos inválidos responde 422.
4. `idempotent()` y `cache()`, si están: pueden devolver una respuesta guardada.
5. `asyncable()`/`async()`, si se pide: encola y responde 202.
6. `handle()` → `connectTo()` → `transform()`.
7. Se envía la respuesta.

Una excepción en el paso 6 la recoge `onError()` si está definido; si no, responde 500 (con el
detalle solo en desarrollo). Una ruta que no existe responde 404; si existe con otro método, 405
con la cabecera `Allow`.

### Métodos

| Método | Para qué | Más detalle |
|---|---|---|
| `at('MÉTODO /ruta')` | Método y ruta; `{x}` es un parámetro; varios métodos con `\|` (`'PUT\|PATCH /posts/{id}'`). Un formato inválido falla al cargar | — |
| `name('a.b')` | Nombre de la ruta | [`point call`](#point-call), [`connectTo`](#encadenar-endpoints-connectto) |
| `group('nombre')` | Grupo de middlewares; un grupo no declarado responde 500 | [Grupos y roles](#grupos-y-roles) |
| `through(...)` | Middlewares extra para esta ruta (`'rate_limit:5'`, `'auth:billing'`) | [Límite de peticiones](#límite-de-peticiones) |
| `expects([...])` | Reglas por campo; solo los campos declarados llegan a `handle`; varias llamadas se acumulan | [Datos y validación](#2-datos-y-validación) |
| `guard(fn)` | Comprobación extra antes de validar; `false` → 403 | [`guard`](#guard) |
| `handle(fn)` | La lógica; también acepta `[Clase::class, 'método']` o una clase invocable | [Acciones](#acciones) |
| `cache(segundos)` | Guarda la respuesta GET | [Caché](#caché-cache) |
| `idempotent(segundos)` | Una petición repetida no se ejecuta dos veces | [Idempotencia](#idempotencia-idempotent) |
| `asyncable(keep)` / `async(keep)` | Ejecución en la cola con ticket | [Asíncrono](#asíncrono-a-demanda-asyncable--async) |
| `transform(fn)` | Modifica la respuesta de `handle` antes de enviarla: `->transform(fn ($res) => $res + ['version' => 1])` | — |
| `onError(fn)` | Respuesta propia ante una excepción: `->onError(fn (Throwable $e) => Response::error('No disponible', 503))` | — |
| `uses(Clase::class)` | Inyecta servicios en `handle` | [Servicios](#servicios-uses) |
| `connectTo('nombre')` / `when(fn)` | Encadena otro endpoint | [Encadenar](#encadenar-endpoints-connectto) |
| `mcp('descripción')` | La ruta pasa a ser una herramienta para asistentes de IA | [`point mcp`](#point-mcp-asistentes-de-ia) |

### `$input`

`$input` reúne el cuerpo (JSON o formulario), la query string y los parámetros de ruta (estos tienen
prioridad). Con `expects()` solo llegan los campos declarados, ya convertidos (`integer` → int,
`boolean` → bool, `trim`, `lowercase`…). En rutas con token incluye también los datos del usuario,
que se leen con [`Auth`](#auth).

- Las claves que empiezan por `_` están reservadas para los middlewares: si el cliente las envía, se descartan.
- **Una ruta POST/PUT/PATCH sin `expects` recibe cualquier campo**, y `DB::table(...)->insert($input)` guardaría
  también lo que no esperas (`role`, `is_admin`…). `php point routes` marca esas rutas con `NO-EXPECTS`.

---

## 2. Datos y validación

```php
->expects([
    'email'  => 'required|email|lowercase|unique:users,email',   // texto con |
    'tags'   => ['optional', 'array', 'each:string'],             // o array (para reglas con | dentro)
    'status' => Rules::status(['draft', 'published']),            // o atajos de Rules
])
```
Con datos inválidos responde **422** con `{"status":422,"message":"Validation failed","errors":{"email":["…"]}}`.
Una regla que no existe (errata) responde 500 en lugar de dejar el campo sin validar.

### Reglas nativas

| Tipo | Reglas |
|---|---|
| Presencia | `required`, `optional`, `default:valor` |
| Tipos | `string`, `integer`, `numeric`, `boolean`, `array`, `json` |
| Formatos | `email`, `url`, `uuid`, `ip`, `date[:formato]`, `alpha_num`, `regex:/.../` |
| Tamaño | `min:n`, `max:n`, `between:a,b` (texto: caracteres; número: valor; array: elementos) |
| Valores | `in:a,b`, `not_in:a,b`, `list_in:a,b` (lista separada por comas), `confirmed` (`campo_confirmation`) |
| Base de datos | `unique:tabla,columna`, `exists:tabla,columna` |
| Listas | `each:regla` (aplica la regla a cada elemento) |
| Limpieza | `trim`, `lowercase`, `uppercase`, `strip_tags` |
| Origen | `source:body,query,route` |
| Archivos | `file`, `max_size:kb`, `mime:jpg,png` ([ver Archivos](#archivos)) |

### Atajos `Rules`

`App\Services\Rules` agrupa las combinaciones habituales. Todos admiten `required: false`.

| Atajo | Equivale a |
|---|---|
| `Rules::id()` | `required\|integer\|min:1` |
| `Rules::uuid()` | `required\|uuid` |
| `Rules::status(['a', 'b'])` o `Rules::status(MiEnum::class)` | `required\|in:a,b` (de un enum respaldado, sus valores) |
| `Rules::email(max: 255)` | `required\|trim\|lowercase\|email\|max:255` |
| `Rules::text(max: 255, min: 0)` | `required\|string\|trim\|max:255` (y `min` si se indica) |
| `Rules::boolean()` | `required\|boolean` |
| `Rules::date(format: 'Y-m-d')` | `required\|date:Y-m-d` |
| `...Rules::pagination(maxLimit: 100, defaultLimit: 20)` | `page` (por defecto 1) y `limit` (por defecto 20, máximo 100) |

```php
->expects(['id' => Rules::id(), 'q' => Rules::text(max: 50, required: false), ...Rules::pagination()])
```

### Comparar campos

`gt`, `gte`, `lt` y `lte` comparan números; `after`, `after_or_equal`, `before` y `before_or_equal`, fechas.
El parámetro es otro campo o un valor literal:

```php
'from' => Rules::date(),
'to'   => [...Rules::date(), 'after_or_equal:from'],   // "to" no puede ser anterior a "from"
'max'  => 'optional|integer|gte:min',
'end'  => 'required|date|before:2030-01-01',
```

### Máquina de estados (`Rules::transition`)

El campo solo admite los cambios permitidos **desde el estado actual del registro**, que se lee de la base de datos:

```php
final class OrderFlow
{
    public const STEPS = [
        'pending'   => ['paid', 'cancelled'],
        'paid'      => ['shipped'],
        'shipped'   => ['delivered'],
        'delivered' => [],                // estado final
        'cancelled' => [],
    ];
}

->expects([
    'id'     => Rules::id(),
    'status' => Rules::transition('orders', OrderFlow::STEPS),   // opciones: column:, key: (columna del ID), idField: (campo del ID)
])
```
```
Pedido 7 en "pending":   {"status":"paid"}      → 200
                         {"status":"shipped"}   → 422 "No se puede pasar de 'pending' a 'shipped'. Permitidos: paid, cancelled."
Pedido 9 en "delivered": {"status":"cancelled"} → 422 "'delivered' es un estado final: no admite cambios."
Pedido que no existe:                            → la regla no decide; el handler responde 404
```
- `Rules::canTransition(OrderFlow::STEPS, 'paid', 'shipped')` → `true`: la misma regla en jobs y acciones.
- `Rules::diagram(OrderFlow::STEPS)` genera un diagrama Mermaid para el README.
- Un mapa incoherente (un destino no definido) falla al cargar el endpoint.
- **Dos cambios a la vez:** guarda con `->where('status', $estadoLeido)->update([...])` y responde 409 si no se actualizó ninguna fila.

### Reglas propias

Se definen en `Rules::custom()` (`services/Rules.php`) y se usan por nombre en cualquier endpoint:

```php
public static function custom(): array
{
    return [
        'slug' => [fn ($value) => is_string($value) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) === 1,
                   'El campo :field solo admite minúsculas, números y guiones.'],
    ];
}

->expects(['slug' => 'required|slug'])
```
La comprobación recibe `($value, $param, $input)` y devuelve `true` si es válido, o un texto con el mensaje de error.
No se pueden redefinir las reglas nativas.

### Archivos

```php
->expects(['avatar' => 'required|file|max_size:2048|mime:jpg,png'])
->handle(fn ($in) => Response::created(['path' => $in['avatar']->store('avatars')]))
```
`mime` comprueba la extensión y, para los tipos conocidos, el contenido real del archivo. `store()` guarda
siempre dentro de `storage/uploads` (no admite `..`).

---

## 3. Respuestas

| Método | Código | Cuerpo |
|---|---|---|
| `Response::ok($data, 'mensaje')` | 200 | `{status, message, data}` |
| `Response::created($data)` | 201 | `{status, message, data}` |
| `Response::noContent()` | 204 | sin cuerpo |
| `Response::error('mensaje', 409, $errors)` | el indicado (400 por defecto) | `{status, message, errors?}` |
| `Response::notFound()` | 404 | `{status, message}` |
| `Response::forbidden()` | 403 | `{status, message}` |
| `Response::paginated($result)` | 200 | `{status, data, meta}` |

```php
return $post ? Response::ok($post) : Response::notFound('Post no encontrado');
```
Un array con `'status' => 201` también funciona, y un texto se envía como `text/plain`. El JSON lleva
sangría solo en desarrollo.

---

## 4. Usuario y permisos

### Grupos y roles

Los grupos se declaran en `config/middleware.php`:

```php
'groups' => [
    'public'        => [],
    'protected'     => ['auth'],                 // exige token (por defecto en make:endpoint)
    'authenticated' => ['auth'],
    'admin'         => ['auth:admin'],           // token con rol admin
    'staff'         => ['auth:admin,editor'],    // admin o editor
],
```
```php
->group('staff')     // sin token: 401 · rol no permitido: 403 · admin o editor: pasa
```
El rol va en el token: `Auth::issue(['sub' => 7, 'role' => 'editor'])`, o varios con
`Auth::issue(['sub' => 7, 'roles' => ['editor', 'billing']])` (basta con que uno esté permitido).
`php point routes` muestra quién puede entrar en cada ruta.

### `Auth`

Solo en rutas con token. En una ruta sin token lanzan un error (500) en lugar de devolver `null`.

| Método | Devuelve |
|---|---|
| `Auth::id($input)` | ID del usuario (claim `sub`) |
| `Auth::user($input)` | Todos los claims del token |
| `Auth::roles($input)` | Lista de roles (`role` y `roles`) |
| `Auth::hasRole($input, 'admin', 'editor')` | `true` si tiene al menos uno |
| `Auth::issue(['sub' => 1, 'role' => 'editor'])` | Emite un token (login); caduca en `JWT_TTL` segundos |
| `Auth::verify($token)` | Claims del token, o `null` si no es válido |
| `Auth::rolesFromClaims($claims)` | Roles de unos claims (lo usa el middleware `auth`) |

Los tokens llevan `iss` y `aud` (`JWT_ISSUER`/`JWT_AUDIENCE` en `.env`; por defecto `point`/`point-api`) y solo
se aceptan los que coinciden: un JWT de otro sistema que comparta el secreto no vale.

### `guard`

Una comprobación extra que se ejecuta antes de validar. Recibe el input **sin validar** (con `_user`); si
devuelve `false`, responde 403:

```php
->guard(fn ($in) => Auth::hasRole($in, 'admin') || (int) $in['id'] === Auth::id($in))   // admin o el propio usuario
```

---

## 5. Rendimiento y fiabilidad

### Caché (`cache`)

```php
Endpoint::from(__FILE__)->at('GET /stats')->group('protected')->cache(60)->handle(...);
```
```
GET /stats   →  200 (X-Cache: MISS)   se ejecuta y se guarda 60 s
GET /stats   →  200 (X-Cache: HIT)    misma respuesta, sin ejecutar
```
- Solo peticiones GET/HEAD y solo respuestas 2xx. Los errores se recalculan siempre.
- La clave incluye la ruta, los campos validados y el usuario: **nunca mezcla datos de usuarios distintos**.
  Un parámetro que no está en `expects` no cambia la clave (el handler tampoco lo ve).
- Los datos guardados no se actualizan hasta que caducan: elige el tiempo según cuánto pueden esperar.

### Idempotencia (`idempotent`)

Para operaciones que no deben repetirse (pagos, pedidos). El cliente envía una clave única por operación:

```php
Endpoint::from(__FILE__)->at('POST /orders')->group('protected')->idempotent()   // la clave dura 24 h (idempotent(3600) para 1 h)
    ->expects(CreateOrder::rules())
    ->handle(fn ($in) => Response::created((new CreateOrder)($in)));
```
```
POST /orders  Idempotency-Key: 9f1c…  {"items":[5]}  →  201 {"data":{"id":31}}
POST /orders  Idempotency-Key: 9f1c…  {"items":[5]}  →  201 {"data":{"id":31}}   (Idempotent-Replayed: true; no crea otro)
POST /orders  Idempotency-Key: 9f1c…  {"items":[8]}  →  422 "Esta Idempotency-Key ya se usó con otros datos."
(la primera aún en curso)                             →  409 "Ya hay una petición en curso con esta Idempotency-Key."
POST /orders  (sin la cabecera)                       →  se ejecuta normal
```
- La clave es por ruta y por usuario (dos usuarios pueden usar la misma).
- No se guardan las respuestas 5xx: un error del servidor se puede reintentar con la misma clave.
- En el frontend: genera la clave al pulsar el botón (`crypto.randomUUID()`) y reutilízala en los reintentos.

### Asíncrono a demanda (`asyncable` / `async`)

```php
->asyncable()   // el cliente elige: esperar (como siempre) o recibir un ticket
->async()       // siempre en la cola
```
```
POST /reports/annual  {"year":2025}                          →  200 tras esperar (sin la cabecera)
POST /reports/annual  {"year":2025}  Prefer: respond-async   →  202 {"job":"7f3a…","state":"queued","status_url":"/jobs/7f3a…"}
GET  /jobs/7f3a…                                             →  {"state":"running","progress":58}
GET  /jobs/7f3a…                                             →  {"state":"done","result_status":200,"result":{…}}
```
- Permisos y validación se comprueban **antes** de encolar: un 401, 403 o 422 llega al momento.
- `GET /jobs/{id}` solo lo ve quien lanzó el trabajo (con su token). El resultado se guarda 24 h (`->asyncable(keep: 3600)`).
- Avance desde el handler o la acción: `Core\Job::progress(40)`.
- Si falla: `{"state":"failed","error":…}` (el detalle, solo en desarrollo). Se ejecuta una sola vez, sin reintentos.
- Necesita el worker: `php point work` (con la imagen Docker, `WORKER_ENABLED=true`, en el mismo contenedor). En desarrollo, con `QUEUE_SYNC=true`, se ejecuta al momento.
- El handler no debe leer cabeceras ni `$_SERVER`: en el worker no hay petición HTTP (las acciones ya cumplen esto).
- Junto con `->idempotent()`, un reintento del cliente no crea dos trabajos.

### Límite de peticiones

```php
->through('rate_limit:5')    // 5 peticiones por minuto por usuario (o por IP si no hay token); al pasarse, 429
```

---

## 6. Organizar la lógica

### Acciones

La lógica del negocio va en una acción: un archivo aparte, sin nada de HTTP, que pueden usar endpoints,
jobs, seeds y tests.

```bash
php point make:action CreateUser      # crea services/Actions/CreateUser.php
```
```php
final class CreateUser
{
    public static function rules(): array          // los datos que acepta
    {
        return ['name' => Rules::text(max: 100), 'email' => [...Rules::email(), 'unique:users,email']];
    }

    public function __invoke(array $data): array    // la operación
    {
        return DB::table('users')->insertReturning($data, 'id, name, email');
    }
}
```
```php
// endpoint
->expects(CreateUser::rules())->handle(fn ($in) => Response::created((new CreateUser)($in)))

// job o importación: mismas reglas
$v = new Core\Validator($fila, CreateUser::rules());
if ($v->passes()) (new CreateUser)($v->validated());
```
Regla: la acción **no sabe nada de HTTP**. No usa `Response::` (devuelve datos) ni lee `$_GET` o `$input['_user']`.
Si necesita al usuario, se le pasa: `(new CreatePost)($in, authorId: Auth::id($in))`.

### CRUD en un archivo (`Endpoint::resource`)

```php
Endpoint::resource('/posts')
    ->group('staff')
    ->expects(['title' => Rules::text(max: 200)])   // se aplica a create y update
    ->list(fn ($in) => Response::ok(DB::table('posts')->get()))                                    // GET       /posts
    ->show(fn ($in) => Response::ok(DB::table('posts')->where('id', $in['id'])->first()))          // GET       /posts/{id}
    ->create(fn ($in) => Response::created(DB::table('posts')->insertReturning($in, 'id, title'))) // POST      /posts
    ->update(fn ($in) => Response::ok(...))                                                        // PUT|PATCH /posts/{id}
    ->delete(fn ($in) => Response::noContent());                                                   // DELETE    /posts/{id}
```
Nombres generados: `posts.list`, `posts.show`, `posts.create`, `posts.update` y `posts.delete`. `Resource` no admite `uses()`.

### Receta: una pantalla, una petición

Cuando una pantalla necesita datos de varias rutas, un endpoint agregador los junta llamando a las **mismas acciones**:

```php
// endpoints/dashboard.php
Endpoint::from(__FILE__)->at('GET /dashboard')->group('protected')->cache(30)
    ->handle(fn ($in) => Response::ok([
        'me'    => (new GetUser)(Auth::id($in)),
        'posts' => (new ListPosts)(['limit' => 5]),
        'stats' => (new GetStats)(),
    ]));
```
Los permisos son los del agregador: si una parte exige un rol, compruébalo aquí.

### Encadenar endpoints (`connectTo`)

Una cadena: la respuesta de un endpoint es la entrada del siguiente (filtrada por su `expects`), y solo se
devuelve la del último. Sirve para flujos («crear el pedido → notificar»), **no para juntar datos**
(para eso, la receta anterior):

```php
->name('orders.create')->connectTo('orders.notify')
->connectTo('audit.log')->when(fn ($res) => ($res['status'] ?? 200) === 201)   // solo si se creó
```
```
connectTo:  A ──► B ──► C ──► (solo C)
agregador:  ┌► acción A ─┐
            ├► acción B ─┼──► { a, b, c }
            └► acción C ─┘
```

### Servicios (`uses`)

```php
->uses(Mailer::class)->handle(fn ($in, Mailer $mailer) => ...)   // se crea con sus dependencias
```
Fuera de un endpoint: `service(Mailer::class)`.

---

## 7. Herramientas de terminal

### Ver y probar rutas

```bash
php point routes             # tabla de rutas: método, grupo, ACCESS (public, token, roles: …), FIELDS y avisos
php point make:http          # requests.http con una petición de ejemplo por ruta (REST Client / PhpStorm)
php point test --smoke       # llama a todas las rutas GET con datos de ejemplo; falla si alguna da 5xx
php point test --smoke=all   # también POST/PUT/PATCH/DELETE (escriben en la base: usa .env.testing)
```
- `routes` avisa con `UNDEFINED GROUP` si una ruta usa un grupo inexistente, y con `NO-EXPECTS` si una ruta de escritura no valida.
- `requests.http` está en `.gitignore` (puede llevar un token). `make:http --force` lo regenera; `--output=archivo` elige otra ruta.

### `point call`

Ejecuta cualquier endpoint con el pipeline real (permisos, validación, caché), útil para scripts, cron e importaciones:

```bash
php point call posts.list                                  # por nombre (->name())
php point call "PUT /posts/7" status=published --as=1      # por método y ruta; --as actúa como el usuario 1
php point call orders.create items='[5,9]' --as=1 --role=admin --data | jq .id
echo '{"title":"Hola"}' | php point call posts.create - --as=1
0 7 * * *  cd /app && php point call reports.daily --as=1  # en el cron
```
- `campo=valor`: los valores JSON (`[5,9]`, `true`, `12`) conservan su tipo. Los `{parámetros}` de la ruta salen de los campos (`id=7`).
- Cuerpo JSON por stdin con `-` (o si llega por una tubería). `--data` imprime solo `data`. `--method=PUT` si la ruta tiene varios.
- Código de salida: 0 (2xx), 1 (4xx) y 2 (5xx), útil con `set -e`.
- `--as` solo existe en la terminal: quien ejecuta `point` ya tiene acceso al servidor.

### `point mcp` (asistentes de IA)

Las rutas marcadas con `->mcp('descripción')` pasan a ser herramientas para asistentes de IA (Model Context
Protocol). Los campos y sus reglas salen de `expects`:

```php
->name('orders.list')->mcp('Lista pedidos. Filtra por estado (pending, paid, shipped) y antigüedad en días.')
```
```bash
claude mcp add mi-api -- php point mcp --as=1      # Claude Code, desde la carpeta del proyecto
```
```json
{ "mcpServers": { "mi-api": { "command": "php", "args": ["/ruta/mi-api/point", "mcp", "--as=1"] } } }
```
(Claude Desktop, archivo de configuración.)

> «¿Cuántos pedidos pagados llevan más de 3 días sin enviarse?» → la IA llama a `orders_list` y responde.

- La IA actúa como el usuario de `--as` (y `--role`), con sus permisos. Sin `--as`, las rutas protegidas responden 401.
- Las GET se marcan como solo lectura y las DELETE como destructivas: el asistente pide confirmación antes de escribir.
- Los errores de validación llegan a la IA, que se corrige con ellos. Cada llamada queda en el log (`mcp.call`).
- Expón sobre todo datos estructurados: un texto libre escrito por usuarios podría intentar dar órdenes a la IA.

### Logs

```bash
php point logs                     # últimas 50 líneas de hoy, con colores por nivel
php point logs --level=error       # nivel mínimo: debug, info, warning o error
php point logs --grep=login -f     # sigue en vivo las líneas que contienen "login" (Ctrl+C para salir)
php point logs --date=2026-10-01 --lines=200
```
Desde el código: `Core\Log::info('pedido.creado', ['id' => 31])` (también `debug`, `warning` y `error`).

### Documentación (Swagger)

```bash
php point openapi            # genera openapi.json a partir de las rutas y sus reglas
php point openapi --serve    # lo abre con Swagger UI en http://localhost:8081 (--port=N para otro)
```
Desde Swagger se prueban los endpoints con «Try it out». Para las rutas protegidas, pulsa «Authorize» y pega un token.

### Todos los comandos

| Comando | Qué hace |
|---|---|
| `init <nombre>` | Crea un proyecto nuevo desde la plantilla |
| `serve [puerto]` | Servidor de desarrollo (8080 por defecto) |
| `routes` | Lista las rutas, con acceso y campos |
| `call <ruta> [campo=valor] [--as=ID]` | Ejecuta un endpoint desde la terminal |
| `mcp [--as=ID] [--role=r]` | Servidor MCP para asistentes de IA |
| `logs [--level] [--grep] [-f]` | Logs con colores; `-f` en vivo |
| `openapi [--serve]` | Documentación OpenAPI / Swagger UI |
| `test [filtro\|--smoke[=all]]` | Ejecuta los tests, o la prueba de humo |
| `make:endpoint <ruta> [opciones]` | Crea un endpoint |
| `make:action <Nombre>` | Crea una acción (lógica reutilizable) |
| `make:http [--force] [--output=archivo]` | Genera `requests.http` |
| `make:test <nombre>` | Crea `tests/<nombre>_test.php` |
| `make:service <nombre>` | Crea un servicio |
| `make:middleware <nombre>` | Crea un middleware |
| `make:job <nombre>` / `make:listener <nombre>` | Crea un job / un listener de eventos |
| `make:migration <tabla> [create\|alter]` / `make:seed <tabla>` | Crea una migración / un seed |
| `migrate` / `rollback` / `seed` | Aplica / deshace migraciones; ejecuta seeds |
| `db:sql <archivo>\|--all [--force]` | Aplica SQL crudo en una transacción |
| `work [--once] [--max-jobs=N] [--max-time=S]` | Worker de la cola (jobs y endpoints asíncronos) |
| `work:failed` / `work:retry` / `work:flush` | Lista / reintenta / borra los jobs fallidos |
| `cache` / `cache:clear` | Genera / borra el manifiesto de rutas (carga diferida en producción) |
| `plugin:list` / `plugin:add <slug>` / `plugin:remove <slug> [--purge]` / `make:plugin <nombre>` | Plugins |

`php point` sin argumentos muestra la ayuda completa.
