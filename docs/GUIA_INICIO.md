# Guías de inicio de Point

Aprende Point construyendo, paso a paso, **Tareas**: una API para gestionar proyectos y tareas. Cada
guía es corta, añade algo al mismo proyecto y se apoya en la anterior. Para consultar una función
concreta, usa la [referencia](REFERENCIA_ENDPOINT.md).

| # | Guía | Qué construyes | Qué aprendes | Tiempo |
|---|---|---|---|---|
| 1 | [Hola, Point](guias/01-hola.md) | El proyecto y `GET /hola` | Crear y arrancar un proyecto, escribir un endpoint, `routes`, `call` | 10 min |
| 2 | [Base de datos y primera tabla](guias/02-base-de-datos.md) | Tabla `projects`: listar, ver y crear | Conexión, migraciones, seeds, `DB::table()`, validación, `Rules`, paginación, 404 | 25 min |
| 3 | [Usuarios y login](guias/03-usuarios-y-login.md) | Registro, login, `GET /me` y proyectos con dueño | Contraseñas, acciones, JWT, grupo `protected`, `Auth::id()`, límite de intentos | 30 min |
| 4 | [Tareas con reglas de negocio](guias/04-tareas-y-reglas.md) | Tareas con estados, fechas y prioridad | Comparar campos, máquina de estados, reglas propias, `guard`, `onError` | 30 min |
| 5 | [Tests y herramientas](guias/05-tests-y-herramientas.md) | Tests del proyecto y documentación | `make:test`, prueba de humo, `make:http`, Swagger, logs | 20 min |
| 6 | [Roles, CRUD y panel](guias/06-roles-crud-y-panel.md) | Roles, etiquetas (CRUD completo), panel y adjuntos | Grupos y roles, `Endpoint::resource`, agregador, `connectTo`, archivos | 35 min |
| 7 | [Rendimiento y segundo plano](guias/07-rendimiento-y-segundo-plano.md) | Caché, altas sin duplicados, exportación en segundo plano, avisos y recordatorios | `cache`, `idempotent`, asíncrono, jobs, eventos, scheduler, plugins | 40 min |
| 8 | [A producción](guias/08-produccion.md) | Despliegue y asistente de IA | Docker, Railway (web + worker), CI, `point mcp` | 30 min |

**Necesitas:** PHP 8.4 o superior, [Composer](https://getcomposer.org/), git y (desde la guía 2) una
base de datos Postgres o MySQL.

---

**¿Cómo están verificadas?** Un test del repositorio sigue las ocho guías en orden sobre un proyecto
nuevo y una base Postgres vacía, y comprueba que cada paso responde lo que dice el texto. Si un cambio
en Point rompe una guía, la CI falla.

**¿Ya conoces Point?** Ve directo a la [referencia](REFERENCIA_ENDPOINT.md): todas las funciones, con
ejemplos y un índice.
