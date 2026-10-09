<?php

use Core\Auth;
use Core\Endpoint;
use Core\Response;

// Cada ejecución real del handler devuelve un valor distinto: si se repite, vino de la caché
Endpoint::from(__FILE__)->at('GET|POST /_test/p30/cached')->group('public')->cache(60)
    ->expects(['q' => 'optional|string'])
    ->handle(fn () => Response::ok(['n' => bin2hex(random_bytes(8))]));

Endpoint::from(__FILE__)->at('GET /_test/p30/cached-user')->group('protected')->cache(60)
    ->expects(['q' => 'optional|string'])
    ->handle(fn (array $in) => Response::ok(['user' => Auth::id($in), 'n' => bin2hex(random_bytes(8))]));

Endpoint::from(__FILE__)->at('GET /_test/p30/cached-error')->group('public')->cache(60)
    ->expects(['q' => 'optional|string'])
    ->handle(fn () => Response::notFound());
