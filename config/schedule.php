<?php

use Core\Middleware\RateLimitMiddleware;
use Plugins\Scheduler\Scheduler;

// Cron del servidor (uno solo): * * * * * cd /ruta/app && php scheduler run >> storage/logs/cron.log 2>&1

// Contadores del rate limit vencidos (storage/ratelimit); el tope por shard es la red de seguridad
Scheduler::call(fn () => RateLimitMiddleware::purgeExpired())
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->description('Limpiar contadores de rate limit vencidos');
