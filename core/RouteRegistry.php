<?php
declare(strict_types=1);
namespace Core;

class RouteRegistry
{
    private static $instance;
    private $routes = [];
    private $namedRoutes = [];
    private $manifest = null;
    private $baseDir = '';
    private $loadedFiles = [];
    private $endpointDirs = [];

    private function __construct() {}

    public static function getInstance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function addRoute(array $route): void
    {
        $this->routes[] = $route;

        if (isset($route['name'])) {
            $this->namedRoutes[$route['name']] = $route;
        }
    }

    public function getRoutes(): array
    {
        return $this->routes;
    }

    public function setManifest(array $manifest, string $baseDir): void
    {
        $this->manifest = $manifest;
        $this->baseDir = rtrim($baseDir, '/');
    }

    /**
     * Directorios que se escanean en busca de endpoints: el del proyecto mas
     * los que aporten los plugins activos. Se usa tanto para cargar como para
     * calcular el hash de frescura del manifiesto.
     */
    public function setEndpointDirs(array $dirs): void
    {
        $this->endpointDirs = array_values(array_filter($dirs, 'is_dir'));
    }

    public function getEndpointDirs(): array
    {
        return $this->endpointDirs;
    }

    public function findByName(string $name): ?array
    {
        if (isset($this->namedRoutes[$name])) {
            return $this->namedRoutes[$name];
        }

        if ($this->manifest !== null && isset($this->manifest['names'][$name])) {
            $this->loadFile($this->manifest['names'][$name]);
            return $this->namedRoutes[$name] ?? null;
        }

        return null;
    }

    public function findByPathAndMethod(string $path, string $method): ?array
    {
        $found = $this->match($path, $method);
        if ($found !== null || $this->manifest === null) {
            return $found;
        }

        // Lazy: localizar el archivo en el manifiesto y cargar solo ese
        foreach ($this->manifest['routes'] as $entry) {
            if (in_array($method, $entry['methods']) && $this->pathMatches($entry['path'], $path)) {
                $this->loadFile($entry['file']);
                return $this->match($path, $method);
            }
        }

        return null;
    }

    public function buildManifest(string $projectDir): array
    {
        $projectDir = rtrim($projectDir, '/');
        $routes = [];
        $names = [];

        foreach ($this->routes as $route) {
            if (empty($route['file'])) {
                continue;
            }
            $file = ltrim(str_replace($projectDir, '', $route['file']), '/');

            $routes[] = [
                'methods' => $route['methods'],
                'path' => $route['path'],
                'name' => $route['name'],
                'file' => $file,
            ];
            if (!empty($route['name'])) {
                $names[$route['name']] = $file;
            }
        }

        return [
            'hash' => self::endpointsHash($this->endpointDirs ?: $projectDir . '/endpoints'),
            'routes' => $routes,
            'names' => $names,
        ];
    }

    /** @param string|array $dir Un directorio o varios (proyecto + plugins). */
    public static function endpointsHash($dir): string
    {
        $items = [];

        foreach ((array) $dir as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $items[] = $file->getPathname() . ':' . $file->getMTime();
                }
            }
        }

        sort($items);

        return md5(implode('|', $items));
    }

    private function match(string $path, string $method): ?array
    {
        foreach ($this->routes as $route) {
            if (in_array($method, $route['methods']) && $this->pathMatches($route['path'], $path)) {
                return $route;
            }
        }
        return null;
    }

    private function loadFile(string $relativeFile): void
    {
        if (isset($this->loadedFiles[$relativeFile])) {
            return;
        }
        $this->loadedFiles[$relativeFile] = true;
        require $this->baseDir . '/' . $relativeFile;
    }

    private function pathMatches(string $routePath, string $requestPath): bool
    {
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)(:[a-zA-Z]+)?\}/', '([^/]+)', $routePath);
        $pattern = '#^' . $pattern . '$#';
        return (bool) preg_match($pattern, $requestPath);
    }

    public function clear(): void
    {
        $this->routes = [];
        $this->namedRoutes = [];
        $this->manifest = null;
        $this->loadedFiles = [];
    }
}
