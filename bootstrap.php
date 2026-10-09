<?php

use Core\Auth;
use Core\ErrorHandler;
use Core\Container;
use Core\Middleware\MiddlewareManager;
use Core\Log;
use Core\Cache;
use Core\Event;
use Core\PluginManager;

require_once __DIR__ . '/core/helpers.php';

// Las variables del entorno del proceso (Docker/Railway, tests) van primero: como Dotenv es
// inmutable, .env solo completa las que falten y nunca las pisa
foreach (getenv() as $key => $value) {
    $_ENV[$key] ??= $value;
}

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$envFile = __DIR__ . '/.env.' . ($_ENV['APP_ENV'] ?? 'development');
if (file_exists($envFile)) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__, '.env.' . $_ENV['APP_ENV']);
    $dotenv->safeLoad();
}

if (!empty($_ENV['TIME_ZONE'])) {
    date_default_timezone_set($_ENV['TIME_ZONE']);
}

define('BASE_PATH', __DIR__);
define('ENDPOINTS_DIR', realpath(__DIR__ . '/endpoints'));

ErrorHandler::register();

Log::configure(__DIR__ . '/storage/logs', $_ENV['LOG_LEVEL'] ?? 'debug');
Cache::configure(__DIR__ . '/storage/cache');

// Fail-fast: en produccion, un JWT_SECRET sin configurar no debe esperar al primer login
// para fallar (Auth::secret() ya lo valida, pero de forma perezosa).
if (($_ENV['APP_ENV'] ?? 'development') === 'production') {
    Auth::assertConfigured();
}

// Plugins: autoloader, alias de compatibilidad, helpers y register().
// Se cargan antes que la config de la app para que esta pueda sobrescribirlos.
$pluginsConfig = __DIR__ . '/config/plugins.php';
PluginManager::load(__DIR__, file_exists($pluginsConfig) ? require $pluginsConfig : null);

$eventsConfig = __DIR__ . '/config/events.php';
if (file_exists($eventsConfig)) {
    foreach (require $eventsConfig as $eventName => $listeners) {
        $listeners = is_array($listeners) ? $listeners : [$listeners];
        foreach ($listeners as $listener) {
            Event::listen($eventName, $listener);
        }
    }
}

// Cargar y registrar middlewares desde config
$middlewareConfig = require __DIR__ . '/config/middleware.php';

foreach ($middlewareConfig['individual'] ?? [] as $name => $handler) {
    MiddlewareManager::register($name, $handler);
}

foreach ($middlewareConfig['inline'] ?? [] as $name => $handler) {
    MiddlewareManager::register($name, $handler);
}

foreach ($middlewareConfig['global'] ?? [] as $middleware) {
    MiddlewareManager::global($middleware);
}

foreach ($middlewareConfig['groups'] ?? [] as $group => $middlewares) {
    MiddlewareManager::defineGroup($group);
    $middlewares = is_array($middlewares) ? $middlewares : [$middlewares];
    foreach ($middlewares as $middleware) {
        MiddlewareManager::group($group, $middleware);
    }
}

// Reglas de validación propias, definidas en services/Rules.php (Rules::custom())
if (class_exists(\App\Services\Rules::class) && method_exists(\App\Services\Rules::class, 'custom')) {
    \Core\Validator::extendMany(\App\Services\Rules::custom());
}

// Registrar bindings explícitos de services
$servicesConfig = __DIR__ . '/config/services.php';
if (file_exists($servicesConfig)) {
    foreach (require $servicesConfig as $key => $concrete) {
        Container::bind($key, $concrete);
    }
}

// Arranque de plugins: ya estan disponibles env, container, middlewares y events
PluginManager::boot();

// Los comandos CLI que no atienden peticiones (scheduler, workers) se saltan las rutas
if (defined('POINT_NO_ROUTES')) {
    return;
}

// Carga de endpoints: lazy si existe manifiesto (point cache), completa si no
$registry = Core\RouteRegistry::getInstance();
$endpointDirs = array_merge([__DIR__ . '/endpoints'], PluginManager::endpointDirs());
// Rutas de prueba solo bajo `php point test` (POINT_TESTING=1)
if (getenv('POINT_TESTING') && is_dir(__DIR__ . '/tests/fixtures/endpoints')) {
    $endpointDirs[] = __DIR__ . '/tests/fixtures/endpoints';
}
$registry->setEndpointDirs($endpointDirs);

$manifestFile = __DIR__ . '/cache/routes.php';
$lazy = false;

if (!defined('POINT_NO_MANIFEST') && file_exists($manifestFile)) {
    $manifest = require $manifestFile;
    $isDev = ($_ENV['APP_ENV'] ?? 'development') === 'development';

    // En dev se verifica frescura contra los mtime; en produccion se confia en el manifiesto
    if (!$isDev || ($manifest['hash'] ?? '') === Core\RouteRegistry::endpointsHash($registry->getEndpointDirs())) {
        $registry->setManifest($manifest, __DIR__);
        $lazy = true;
    }
}

if (!$lazy) {
    foreach ($registry->getEndpointDirs() as $dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                require $file->getPathname();
            }
        }
    }

    // Manifiesto obsoleto en dev: regenerarlo automaticamente
    if (!defined('POINT_NO_MANIFEST') && file_exists($manifestFile)) {
        file_put_contents($manifestFile, '<?php return ' . var_export($registry->buildManifest(__DIR__), true) . ';');
    }
}
