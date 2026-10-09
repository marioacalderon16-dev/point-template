<?php

use Core\Endpoint;

Endpoint::from(__FILE__)
    ->at('GET /health')
    ->name('health')
    ->through('rate_limit:120')
    ->handle(fn () => health());
