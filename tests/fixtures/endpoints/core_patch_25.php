<?php

use Core\Endpoint;
use Core\Response;

Endpoint::from(__FILE__)->at('POST /_test/p25/typo')->group('public')
    ->expects(['email' => 'required|emial'])
    ->handle(fn () => Response::ok(['data' => 'no debería llegar aquí']));

Endpoint::from(__FILE__)->at('POST /_test/p25/slug')->group('public')
    ->expects(['slug' => 'required|slug'])
    ->handle(fn (array $d) => Response::ok($d));

Endpoint::from(__FILE__)->at('POST /_test/p25/range')->group('public')
    ->expects([
        'from' => 'required|date',
        'to'   => 'required|date|after_or_equal:from',
        'min'  => 'optional|integer',
        'max'  => 'optional|integer|gte:min',
    ])
    ->handle(fn (array $d) => Response::ok($d));

Endpoint::from(__FILE__)->at('GET /_test/p25/billing')->group('public')->through('auth:billing')
    ->handle(fn () => Response::ok(['ok' => true]));
