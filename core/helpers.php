<?php
declare(strict_types=1);

function service(string $class)
{
    return Core\Container::resolve($class);
}

function dispatch(string $job, array $data = [], int $delay = 0): string
{
    return Core\Queue::dispatch($job, $data, $delay);
}

function event(string $name, array $payload = []): array
{
    return Core\Event::fire($name, $payload);
}

function cache(string $key, ?int $ttl = null, ?callable $fallback = null): mixed
{
    return Core\Cache::get($key, $ttl, $fallback);
}

function logger(string $level, string $message, array $context = []): void
{
    Core\Log::{$level}($message, $context);
}

function response(): string
{
    return Core\Response::class;
}

function can(string $accion): callable
{
    return function (array $input) use ($accion): bool {
        $role = $input['_user']['role'] ?? null;
        if (!$role) return false;

        $permisos = require __DIR__ . '/../config/permissions.php';
        $acciones = $permisos[$role]['acciones'] ?? [];

        return in_array('*', $acciones, true) || in_array($accion, $acciones, true);
    };
}

// health(), apiVersion(), webhook(), maintenance() y ai() los aportan
// los plugins correspondientes desde plugins/<slug>/helpers.php
