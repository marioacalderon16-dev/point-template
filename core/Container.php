<?php
declare(strict_types=1);
namespace Core;

class Container
{
    private static $bindings = [];
    private static $instances = [];

    public static function bind(string $key, $concrete): void
    {
        self::$bindings[$key] = $concrete;
        unset(self::$instances[$key]);
    }

    public static function get(string $key)
    {
        if (!isset(self::$bindings[$key])) {
            throw new \Exception("Servicio no registrado: {$key}");
        }

        if (isset(self::$instances[$key])) {
            return self::$instances[$key];
        }

        $concrete = self::$bindings[$key];
        $instance = is_callable($concrete) ? $concrete() : $concrete;
        self::$instances[$key] = $instance;

        return $instance;
    }

    public static function resolve(string $class)
    {
        if (isset(self::$instances[$class])) {
            return self::$instances[$class];
        }

        if (isset(self::$bindings[$class])) {
            return self::get($class);
        }

        if (!class_exists($class)) {
            throw new \Exception("Clase no encontrada: {$class}");
        }

        $ref = new \ReflectionClass($class);

        if (!$ref->isInstantiable()) {
            throw new \Exception("No se puede instanciar: {$class}");
        }

        $constructor = $ref->getConstructor();

        if (!$constructor || $constructor->getNumberOfParameters() === 0) {
            $instance = new $class();
        } else {
            $params = [];
            foreach ($constructor->getParameters() as $param) {
                $type = $param->getType();
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $params[] = self::resolve($type->getName());
                } elseif ($param->isDefaultValueAvailable()) {
                    $params[] = $param->getDefaultValue();
                } else {
                    throw new \Exception(
                        "No se puede resolver el parametro '{$param->getName()}' de {$class}"
                    );
                }
            }
            $instance = $ref->newInstanceArgs($params);
        }

        self::$instances[$class] = $instance;
        return $instance;
    }

    public static function has(string $key): bool
    {
        return isset(self::$bindings[$key]) || class_exists($key);
    }

    public static function clear(): void
    {
        self::$instances = [];
    }
}