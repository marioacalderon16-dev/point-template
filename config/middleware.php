<?php

return [

    'individual' => [
        'cors'       => [\Core\Middleware\CorsMiddleware::class, 'handle'],
        'auth'       => [\Core\Middleware\AuthMiddleware::class, 'handle'],
        'rate_limit' => [\Core\Middleware\RateLimitMiddleware::class, 'handle'],
        'logger'     => [\Core\Middleware\LoggerMiddleware::class, 'handle'],
        'security'   => [\Core\Middleware\SecurityHeadersMiddleware::class, 'handle'],
    ],

    'global' => [
        'cors',
        'security',
    ],

    'groups' => [
        'public'        => [],
        'protected'     => ['auth'],   // default de make:endpoint
        'authenticated' => ['auth'],
        'admin'         => ['auth:admin'],
        'staff'         => ['auth:admin,editor'],   // ejemplo: varios roles permitidos
    ],

    'inline' => [],

];
