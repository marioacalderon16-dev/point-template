<?php

use Core\Endpoint;
use Core\Response;

// Espacios de más alrededor y entre método y ruta
Endpoint::from(__FILE__)->at('  GET   /_test/p28/spaces  ')->group('public')
    ->handle(fn () => Response::ok());

Endpoint::from(__FILE__)->at('POST /_test/p28/merge')->group('public')
    ->expects(['a' => 'required|string'])
    ->expects(['b' => 'required|string'])
    ->handle(fn (array $in) => Response::ok(['keys' => array_keys($in)]));

Endpoint::from(__FILE__)->at('GET /_test/p28/onerror')->group('public')
    ->onError(fn () => 'Servicio no disponible')
    ->handle(fn () => throw new RuntimeException('fallo interno'));
