<?php
declare(strict_types=1);
namespace Core;

/**
 * Descubre, ordena y arranca los plugins del proyecto.
 *
 * No depende de composer: registra su propio autoloader PSR-4 para el
 * namespace Plugins\ y resuelve los alias de compatibilidad de forma lazy.
 */
final class PluginManager
{
    /** @var array<string, Plugin> slug => instancia */
    private static array $plugins = [];

    /** @var array<string, string> prefijo de namespace => directorio src/ */
    private static array $namespaces = [];

    /** @var array<string, string> clase antigua => clase real */
    private static array $aliases = [];

    /** @var array<string, string> slug => ruta absoluta (todos los encontrados) */
    private static array $available = [];

    private static string $basePath = '';
    private static bool $autoloaderRegistered = false;
    private static bool $loaded = false;
    private static bool $booted = false;

    /**
     * Carga los plugins habilitados: autoloader, alias, helpers y register().
     *
     * @param string     $basePath Raiz del proyecto.
     * @param array|null $enabled  Slugs habilitados. null = todos los encontrados.
     */
    public static function load(string $basePath, ?array $enabled = null): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        self::$basePath = rtrim($basePath, '/');

        self::$available = self::discover(self::$basePath . '/plugins');

        if ($enabled === null) {
            $enabled = array_keys(self::$available);
        }

        $enabled = array_values(array_unique(array_filter($enabled, 'is_string')));

        foreach ($enabled as $slug) {
            if (!isset(self::$available[$slug])) {
                throw new \RuntimeException(
                    "Plugin '{$slug}' habilitado en config/plugins.php pero no existe en plugins/{$slug}/plugin.php"
                );
            }
        }

        // El autoloader debe existir antes de instanciar cualquier clase de plugin.
        foreach ($enabled as $slug) {
            self::$namespaces[self::namespaceFor($slug)] = self::$available[$slug] . '/src/';
        }
        self::registerAutoloader();

        foreach (self::resolveOrder($enabled) as $slug) {
            self::$plugins[$slug] = self::instantiate($slug, self::$available[$slug]);
        }

        // Los alias se registran todos juntos: un plugin puede referenciar
        // el nombre antiguo de una clase de otro plugin.
        foreach (self::$plugins as $plugin) {
            foreach ($plugin->aliases() as $legacy => $real) {
                self::$aliases[ltrim($legacy, '\\')] = ltrim($real, '\\');
            }
        }

        foreach (self::$plugins as $plugin) {
            if ($helpers = $plugin->helpersFile()) {
                require_once $helpers;
            }
        }

        foreach (self::$plugins as $plugin) {
            $plugin->register();
        }
    }

    /** Ejecuta boot() de cada plugin. Se llama tras cargar la config de la app. */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        foreach (self::$plugins as $plugin) {
            $plugin->boot();
        }
    }

    /** Directorios de endpoints aportados por los plugins activos. */
    public static function endpointDirs(): array
    {
        $dirs = [];
        foreach (self::$plugins as $plugin) {
            if ($dir = $plugin->endpointsDir()) {
                $dirs[] = $dir;
            }
        }
        return $dirs;
    }

    /** @return array<string, Plugin> */
    public static function all(): array
    {
        return self::$plugins;
    }

    /** Slugs encontrados en plugins/, esten habilitados o no. */
    public static function available(): array
    {
        if (!self::$loaded && self::$basePath === '' && defined('BASE_PATH')) {
            return array_keys(self::discover(BASE_PATH . '/plugins'));
        }
        return array_keys(self::$available);
    }

    public static function has(string $slug): bool
    {
        return isset(self::$plugins[$slug]);
    }

    public static function get(string $slug): ?Plugin
    {
        return self::$plugins[$slug] ?? null;
    }

    /** Reinicia el estado (solo para tests). */
    public static function reset(): void
    {
        self::$plugins = [];
        self::$namespaces = [];
        self::$aliases = [];
        self::$available = [];
        self::$basePath = '';
        self::$loaded = false;
        self::$booted = false;
    }

    // ────────────────────────────────────────────────

    private static function discover(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $found = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            if (is_file("{$dir}/{$entry}/plugin.php")) {
                $found[$entry] = "{$dir}/{$entry}";
            }
        }

        ksort($found);
        return $found;
    }

    private static function instantiate(string $slug, string $path): Plugin
    {
        $class = require "{$path}/plugin.php";

        if (!is_string($class) || !class_exists($class)) {
            throw new \RuntimeException(
                "plugins/{$slug}/plugin.php debe devolver el nombre de una clase existente que extienda Core\\Plugin"
            );
        }

        $instance = new $class($slug, $path);

        if (!$instance instanceof Plugin) {
            throw new \RuntimeException("{$class} debe extender Core\\Plugin");
        }

        return $instance;
    }

    /** Orden topologico segun requires(); detecta ciclos y dependencias ausentes. */
    private static function resolveOrder(array $enabled): array
    {
        $order = [];
        $state = [];

        $visit = function (string $slug, array $trail) use (&$visit, &$order, &$state, $enabled) {
            if (($state[$slug] ?? null) === 'done') {
                return;
            }
            if (($state[$slug] ?? null) === 'visiting') {
                throw new \RuntimeException(
                    'Dependencia circular entre plugins: ' . implode(' -> ', [...$trail, $slug])
                );
            }

            $state[$slug] = 'visiting';

            foreach (self::requirementsOf($slug) as $dep) {
                if (!in_array($dep, $enabled, true)) {
                    throw new \RuntimeException(
                        "El plugin '{$slug}' requiere '{$dep}', que no esta habilitado en config/plugins.php"
                    );
                }
                $visit($dep, [...$trail, $slug]);
            }

            $state[$slug] = 'done';
            $order[] = $slug;
        };

        foreach ($enabled as $slug) {
            $visit($slug, []);
        }

        return $order;
    }

    /**
     * Lee requires() sin arrastrar el plugin al grafo todavia: se instancia una
     * vez y se reutiliza mas abajo seria mas complejo, y estas clases son triviales.
     */
    private static function requirementsOf(string $slug): array
    {
        $class = require self::$available[$slug] . '/plugin.php';

        if (!is_string($class) || !class_exists($class)) {
            return [];
        }

        return (new $class($slug, self::$available[$slug]))->requires();
    }

    private static function registerAutoloader(): void
    {
        if (self::$autoloaderRegistered) {
            return;
        }
        self::$autoloaderRegistered = true;

        spl_autoload_register(static function (string $class): void {
            // Alias de compatibilidad (Core\Webhook => Plugins\Webhooks\Webhook)
            if (isset(self::$aliases[$class])) {
                $target = self::$aliases[$class];
                if (class_exists($target) || interface_exists($target)) {
                    class_alias($target, $class);
                }
                return;
            }

            foreach (self::$namespaces as $prefix => $dir) {
                if (!str_starts_with($class, $prefix)) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
                $file = $dir . $relative . '.php';
                if (is_file($file)) {
                    require $file;
                }
                return;
            }
        });
    }

    private static function namespaceFor(string $slug): string
    {
        return 'Plugins\\' . self::studly($slug) . '\\';
    }

    private static function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }
}
