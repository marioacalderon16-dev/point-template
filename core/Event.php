<?php
declare(strict_types=1);
namespace Core;

final class Event
{
    private static array $listeners = [];

    public static function listen(string $event, string|callable $listener): void
    {
        self::$listeners[$event][] = $listener;
    }

    public static function fire(string $event, array $payload = []): array
    {
        $results = [];

        foreach (self::$listeners[$event] ?? [] as $listener) {
            try {
                $handler = self::resolve($listener);
                $results[] = call_user_func($handler, $payload, $event);
            } catch (\Throwable $e) {
                Log::error("Event listener failed: {$event}", [
                    'listener' => is_string($listener) ? $listener : 'closure',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    public static function fireAsync(string $event, array $payload = []): void
    {
        foreach (self::$listeners[$event] ?? [] as $listener) {
            if (is_string($listener) && class_exists($listener)) {
                Queue::dispatch($listener, array_merge($payload, ['_event' => $event]));
            }
        }
    }

    public static function has(string $event): bool
    {
        return !empty(self::$listeners[$event]);
    }

    public static function forget(string $event): void
    {
        unset(self::$listeners[$event]);
    }

    public static function clear(): void
    {
        self::$listeners = [];
    }

    private static function resolve(string|callable $listener): callable
    {
        if (is_callable($listener)) {
            return $listener;
        }

        if (is_string($listener) && class_exists($listener)) {
            $instance = Container::resolve($listener);
            if (method_exists($instance, 'handle')) {
                return [$instance, 'handle'];
            }
            if (is_callable($instance)) {
                return $instance;
            }
            throw new \RuntimeException("Listener {$listener} must have a handle() method or be invocable");
        }

        throw new \RuntimeException("Cannot resolve listener: {$listener}");
    }
}
