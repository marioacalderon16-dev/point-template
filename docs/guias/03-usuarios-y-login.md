# Guía 3: usuarios y login

**Tiempo:** 30 minutos · **Requisito previo:** [Guía 2](02-base-de-datos.md)

En esta guía añadimos usuarios a **Tareas**: registro, inicio de sesión con token y una ruta para ver
tu perfil. Al final, solo los usuarios con sesión podrán crear proyectos, y cada proyecto guardará
quién es su dueño.

**Lo que aprenderás:**
- Guardar contraseñas de forma segura.
- Poner la lógica en una **acción**, reutilizable fuera de HTTP.
- Iniciar sesión con **JWT** y proteger rutas con el grupo `protected`.
- Saber quién hace la petición con `Auth::id()`.
- Limitar intentos de login.

---

## Cómo funciona el login con token

1. El usuario envía su email y contraseña a `POST /login`.
2. Si son correctos, la API le devuelve un **token**: una "llave" firmada que caduca (1 hora por defecto).
3. En cada petición a una ruta protegida, el cliente envía la cabecera `Authorization: Bearer <token>`.
4. Point comprueba la firma y sabe quién es el usuario, sin consultar la base de datos.

## Paso 1: tabla de usuarios

```bash
php point make:migration users
```

Reemplaza el archivo creado por:

```php file=database/migrations/2026_01_01_000002_create_users.table.php
<?php
use Core\Scribe\Table;

return Table::create('users')
    ->id()
    ->str('name')
    ->str('email')->unique()
    ->str('password')
    ->stamp();
```

`unique()` impide que dos usuarios tengan el mismo email.

## Paso 2: dueño de cada proyecto

Añadimos a `projects` una columna con el usuario que lo creó. Como la tabla ya existe, es una
migración de **cambio** (`alter`):

```bash
php point make:migration projects alter
```

```php file=database/migrations/2026_01_01_000003_alter_projects.table.php
<?php
use Core\Scribe\Table;

return Table::alter('projects')
    ->addColumn('owner_id')
        ->int()
        ->nullable()
        ->references('users');
```

- `references('users')` enlaza `owner_id` con `users.id`: la base de datos no admitirá un dueño que no exista.
- Es `nullable` porque los proyectos que ya existen (los del seed) no tienen dueño.

```bash
php point migrate           # ✅ 2 migraciones aplicadas.
```

## Paso 3: la acción para crear usuarios

Crear un usuario implica una regla importante: **la contraseña nunca se guarda tal cual**, sino cifrada.
Esa lógica la ponemos en una **acción**: un archivo aparte, sin nada de HTTP, que puede usar este
endpoint, pero también un script, un seed o un test.

```bash
php point make:action CreateUser
```

Reemplaza `services/Actions/CreateUser.php` por:

```php file=services/Actions/CreateUser.php
<?php
declare(strict_types=1);
namespace App\Services\Actions;

use App\Services\Rules;
use Core\DB;

final class CreateUser
{
    /** Datos que acepta la acción: los usa el endpoint y cualquier otro llamador. */
    public static function rules(): array
    {
        return [
            'name'     => Rules::text(max: 100, min: 2),
            'email'    => [...Rules::email(), 'unique:users,email'],
            'password' => Rules::text(max: 72, min: 8),
        ];
    }

    public function __invoke(array $data): array
    {
        $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);

        return DB::table('users')->insertReturning($data, 'id, name, email');
    }
}
```

- `rules()` declara qué datos acepta. `unique:users,email` rechaza un email ya registrado.
- `password_hash()` cifra la contraseña. **Es imprescindible**: sin esta línea, quedaría en texto plano.
- `insertReturning(..., 'id, name, email')` no devuelve la contraseña: el hash nunca sale en la respuesta.
- 72 es el máximo que admite el cifrado (bcrypt).

## Paso 4: registro (`POST /users`)

```bash
php point make:endpoint users/create --post --public
```

```php file=endpoints/users/create.php
<?php

use App\Services\Actions\CreateUser;
use Core\Endpoint;
use Core\Response;

Endpoint::from(__FILE__)
    ->at('POST /users')
    ->name('users.create')
    ->group('public')
    ->expects(CreateUser::rules())
    ->handle(fn ($input) => Response::created((new CreateUser)($input)));
```

El endpoint solo se ocupa de lo HTTP (ruta, acceso, validación y respuesta); la lógica está en la
acción. `expects(CreateUser::rules())` valida con las mismas reglas que la acción.

```bash
curl -s -X POST http://localhost:8090/users -H "Content-Type: application/json" \
  -d '{"name":"Marta","email":"marta@example.com","password":"secreto123"}'        # 201
```

Repite la misma petición: responde **422** porque el email ya existe.

## Paso 5: iniciar sesión (`POST /login`)

```bash
php point make:endpoint login --post --public
```

```php file=endpoints/login.php
<?php

use Core\Auth;
use Core\DB;
use Core\Endpoint;
use Core\Response;

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

        if (!$user) {
            // Mismo tiempo de respuesta que con un email existente: no revela quién está registrado
            password_hash($input['password'], PASSWORD_DEFAULT);
            return Response::error('Credenciales inválidas', 401);
        }

        if (!password_verify($input['password'], $user['password'])) {
            return Response::error('Credenciales inválidas', 401);
        }

        return Response::ok([
            'token'      => Auth::issue(['sub' => $user['id']]),
            'expires_in' => (int) ($_ENV['JWT_TTL'] ?? 3600),
        ]);
    });
```

- `->through('rate_limit:5')`: como máximo 5 intentos por minuto; después responde **429**. Frena a quien intente adivinar contraseñas.
- `password_verify()` compara la contraseña con el hash guardado.
- El mensaje es el mismo si el email no existe o la contraseña es incorrecta, para no dar pistas.
- `Auth::issue(['sub' => $user['id']])` crea el token. `sub` es el ID del usuario.

```bash
curl -s -X POST http://localhost:8090/login -H "Content-Type: application/json" \
  -d '{"email":"marta@example.com","password":"secreto123"}'        # 200 con data.token
curl -s -X POST http://localhost:8090/login -H "Content-Type: application/json" \
  -d '{"email":"marta@example.com","password":"incorrecta"}'        # 401
```

Copia el `token` de la primera respuesta: lo usamos en el siguiente paso.

## Paso 6: mi perfil (`GET /me`)

```bash
php point make:endpoint me
```

Sin `--public`, el endpoint nace en el grupo `protected`: **exige token**.

```php file=endpoints/me.php
<?php

use Core\Auth;
use Core\DB;
use Core\Endpoint;
use Core\Response;

Endpoint::from(__FILE__)
    ->at('GET /me')
    ->name('me')
    ->group('protected')
    ->handle(function ($input) {
        $user = DB::table('users')
            ->select('id', 'name', 'email')
            ->where('id', Auth::id($input))
            ->first();

        return Response::ok($user);
    });
```

`Auth::id($input)` devuelve el ID del usuario del token (el `sub`).

```bash
curl -s http://localhost:8090/me                                   # 401 "Token no proporcionado"
curl -s http://localhost:8090/me -H "Authorization: Bearer TU_TOKEN"   # 200 con tus datos
```

Desde la terminal, `--as` actúa como un usuario sin necesidad de token: `php point call me --as=1`.

## Paso 7: solo usuarios con sesión crean proyectos

Volvemos a `endpoints/projects/create.php` (de la guía 2) para protegerlo y guardar el dueño:

```php file=endpoints/projects/create.php
<?php

use App\Services\Rules;
use Core\Auth;
use Core\DB;
use Core\Endpoint;
use Core\Response;

Endpoint::from(__FILE__)
    ->at('POST /projects')
    ->name('projects.create')
    ->group('protected')
    ->expects([
        'name'        => Rules::text(max: 100, min: 3),
        'description' => Rules::text(max: 1000, required: false),
    ])
    ->handle(function ($input) {
        $project = DB::table('projects')->insertReturning([
            'name'        => $input['name'],
            'description' => $input['description'],
            'owner_id'    => Auth::id($input),
        ], 'id, name, description, owner_id');

        return Response::created($project);
    });
```

Dos cambios:
1. `->group('protected')`: sin token, responde 401.
2. Guardamos los campos **uno a uno**. En una ruta protegida, `$input` también trae los datos del
   usuario (los que usa `Auth::id()`), así que ya no conviene guardar `$input` entero.

```bash
curl -s -X POST http://localhost:8090/projects -H "Content-Type: application/json" -d '{"name":"Intranet"}'      # 401
curl -s -X POST http://localhost:8090/projects -H "Content-Type: application/json" \
  -H "Authorization: Bearer TU_TOKEN" -d '{"name":"Intranet"}'                                                  # 201 con owner_id
```

## Paso 8: comprobar quién puede entrar en cada ruta

```bash
php point routes
```

La columna `ACCESS` muestra `token` en `GET /me` y `POST /projects`, y `public` en el resto.

## Paso 9: guardar el progreso

```bash
git add . && git commit -m "Guía 3: usuarios, login con JWT y proyectos con dueño"
```

---

## Resumen

- Las contraseñas se guardan cifradas con `password_hash()` y se comprueban con `password_verify()`.
- Una **acción** (`make:action`) guarda la lógica y sus reglas (`rules()`); el endpoint solo hace lo HTTP.
- `POST /login` devuelve un token con `Auth::issue()`; las rutas del grupo `protected` lo exigen.
- `Auth::id($input)` dice quién hace la petición. En rutas protegidas, guarda los campos uno a uno.
- `->through('rate_limit:5')` limita los intentos.

**Próximamente:** Guía 4, tareas con reglas de negocio (estados, fechas y prioridades). Mientras tanto,
lo que sigue (tests, herramientas y despliegue) está en [la guía de inicio](../GUIA_INICIO.md).
