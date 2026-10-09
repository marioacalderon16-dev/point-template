<?php

use Core\Endpoint;

Endpoint::from(__FILE__)->at('GET /_test/group/protected')->group('protected')
    ->handle(fn ($input) => ['data' => $input['_user_id'] ?? null]);

Endpoint::from(__FILE__)->at('GET /_test/group/public')->group('public')
    ->handle(fn () => ['data' => 'ok']);

Endpoint::from(__FILE__)->at('GET /_test/group/undefined')->group('no_existe')
    ->handle(fn () => ['data' => 'no debería llegar aquí']);
