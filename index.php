<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/bootstrap.php';

use Core\Middleware\MiddlewareManager;

$context = [
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/',
];

$input = [];

$globalMiddlewares = MiddlewareManager::getGlobal();
if (!MiddlewareManager::execute($globalMiddlewares, $input, $context)) {
    exit;
}

Core\Endpoint::run();
