<?php

use Core\Endpoint;

Endpoint::from(__FILE__)
    ->at('GET /health')
    ->name('health')
    ->handle(fn () => health());
