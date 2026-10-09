<?php

use Core\Auth;
use Core\Endpoint;
use Core\Response;

// Sin expects a propósito: devuelve lo que llega al handler
Endpoint::from(__FILE__)->at('POST /_test/p27/echo')->group('public')
    ->handle(fn (array $in) => Response::ok(['keys' => array_keys($in)]));

Endpoint::from(__FILE__)->at('POST /_test/p27/limited')->group('public')->through('rate_limit:2')
    ->expects(['x' => 'optional|string'])
    ->handle(fn () => Response::ok());

Endpoint::from(__FILE__)->at('GET /_test/p27/admin-guard')->group('public')
    ->guard(fn (array $in) => Auth::hasRole($in, 'admin'))
    ->handle(fn () => Response::ok(['data' => 'no debería llegar aquí']));
