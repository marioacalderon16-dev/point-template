<?php
declare(strict_types=1);
namespace Core;

/**
 * Clase base de todo plugin de Point.
 *
 * Un plugin vive en plugins/<slug>/ y expone su clase a traves de plugin.php:
 *
 *     plugins/mi-plugin/
 *       plugin.php            <?php return \Plugins\MiPlugin\MiPluginPlugin::class;
 *       src/MiPluginPlugin.php
 *       src/...               autocargado como Plugins\MiPlugin\...
 *       helpers.php           (opcional) funciones globales
 *       endpoints/            (opcional) endpoints propios del plugin
 *       config/               (opcional) plantillas de configuracion
 */
abstract class Plugin
{
    protected string $slug;
    protected string $path;

    final public function __construct(string $slug, string $path)
    {
        $this->slug = $slug;
        $this->path = rtrim($path, '/');
    }

    /** Descripcion corta mostrada por `php point plugin:list`. */
    public function description(): string
    {
        return '';
    }

    /** Slugs de otros plugins que deben cargarse antes que este. */
    public function requires(): array
    {
        return [];
    }

    /**
     * Alias de compatibilidad: nombre antiguo => clase real del plugin.
     * Permite que codigo que use Core\X siga funcionando tras mover X a un plugin.
     */
    public function aliases(): array
    {
        return [];
    }

    /**
     * Registro temprano: bindings del contenedor, middlewares, listeners.
     * Se ejecuta antes de cargar la configuracion de la app.
     */
    public function register(): void
    {
    }

    /**
     * Arranque: configuracion que depende de env o de otros plugins ya registrados.
     * Se ejecuta despues de cargar toda la configuracion de la app.
     */
    public function boot(): void
    {
    }

    final public function slug(): string
    {
        return $this->slug;
    }

    final public function path(string $sub = ''): string
    {
        return $sub === '' ? $this->path : $this->path . '/' . ltrim($sub, '/');
    }

    /** Directorio de endpoints del plugin, o null si no tiene. */
    final public function endpointsDir(): ?string
    {
        $dir = $this->path('endpoints');
        return is_dir($dir) ? $dir : null;
    }

    /** Archivo de helpers del plugin, o null si no tiene. */
    final public function helpersFile(): ?string
    {
        $file = $this->path('helpers.php');
        return is_file($file) ? $file : null;
    }

    /**
     * Ruta a un archivo de configuracion de la app para este plugin.
     * Devuelve config/<nombre>.php del proyecto si existe, si no null.
     */
    final public function appConfig(string $name): ?string
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname($this->path, 2);
        $file = $base . '/config/' . $name . '.php';
        return is_file($file) ? $file : null;
    }
}
