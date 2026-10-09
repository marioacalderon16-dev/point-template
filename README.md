# Backend — esqueleto point

Punto de partida mínimo sobre el miniframework **point** (`core/` + ejecutable `point`), listo como base para nuevos proyectos.

## Requisitos

- PHP 8.1+ con extensión `pdo_pgsql` (y `pdo_sqlite` para los tests)
- [Composer](https://getcomposer.org/)

## Instalación

```sh
composer install
cp .env.example .env   # completa las variables (ver .env.example)
```

## Uso

```sh
php point serve 8080   # servidor de desarrollo, http://localhost:8080
php point routes       # listar rutas registradas
php point test         # correr la suite de tests
php point              # ver todos los comandos disponibles (make:endpoint, plugin:add, migrate, ...)
```

## Node.js / npm

Este proyecto **no tiene dependencias de Node.js actualmente** — es PHP puro. No hay ningún `package.json` en el repo y no se necesita `npm install` para nada de lo que existe hoy.

Si en el futuro alguna parte del proyecto necesita herramientas en JavaScript (por ejemplo, un script de verificación en `tests/`), esa carpeta debe tener su propio `package.json`. Para instalarlo:

```sh
cd ruta/de/esa/carpeta
npm install
```

Esto genera una carpeta `node_modules/` local con las dependencias — **nunca se sube al repo** (ya está excluida en `.gitignore`); cada quien la regenera con `npm install` en su máquina.
