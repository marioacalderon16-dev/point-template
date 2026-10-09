<?php
declare(strict_types=1);
namespace Core\Middleware;

class MiddlewareManager
{
    private static $middlewares = [];
    private static $globalMiddlewares = [];
    private static $groupMiddlewares = [];

    /**
     * Registrar un middleware
     */
    public static function register(string $name, callable $handler): void
    {
        self::$middlewares[$name] = $handler;
    }

    /**
     * Agregar middleware global (se ejecuta en todas las rutas)
     */
    public static function global(string $name): void
    {
        if (!in_array($name, self::$globalMiddlewares)) {
            self::$globalMiddlewares[] = $name;
        }
    }

    /**
     * Declarar un grupo (puede quedar vacío, p. ej. 'public')
     */
    public static function defineGroup(string $group): void
    {
        self::$groupMiddlewares[$group] ??= [];
    }

    /**
     * ¿Está declarado el grupo?
     */
    public static function hasGroup(string $group): bool
    {
        return isset(self::$groupMiddlewares[$group]);
    }

    /**
     * Agregar middleware a un grupo específico
     */
    public static function group(string $group, string $middleware): void
    {
        self::defineGroup($group);
        
        if (!in_array($middleware, self::$groupMiddlewares[$group])) {
            self::$groupMiddlewares[$group][] = $middleware;
        }
    }

    /**
     * Obtener todos los middlewares registrados
     */
    public static function getRegistered(): array
    {
        return self::$middlewares;
    }

    /**
     * Obtener middlewares globales
     */
    public static function getGlobal(): array
    {
        return self::$globalMiddlewares;
    }

    /**
     * Obtener middlewares de un grupo
     */
    public static function getGroup(string $group): array
    {
        return self::$groupMiddlewares[$group] ?? [];
    }

    /**
     * Ejecutar cadena de middlewares
     */
    public static function execute(array $middlewareList, array &$input, array &$context = []): bool
    {
        $inputSources = [];

        foreach ($middlewareList as $middleware) {
            $parts = explode(':', $middleware, 2);
            $name = $parts[0];
            $params = $parts[1] ?? '';

            // Fail-closed: un middleware inexistente no puede dejar la ruta desprotegida
            if (!isset(self::$middlewares[$name])) {
                throw new \RuntimeException("Middleware no registrado: {$name}");
            }

            $handler = self::$middlewares[$name];
            $result = $handler($params, $input, $context);

            if ($result === false) {
                return false;
            }

            if (is_array($result)) {
                foreach ($result as $key => $value) {
                    $inputSources[$key] = 'middleware';
                }
                $input = array_merge($input, $result);
            }
        }

        $existing = $context['inputSources'] ?? [];
        $context['inputSources'] = array_merge($existing, $inputSources);

        return true;
    }
}
