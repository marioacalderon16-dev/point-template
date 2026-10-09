<?php

use Core\Auth;
use Core\Endpoint;
use Core\Job;
use Core\Response;

// Cada ejecución real devuelve un valor distinto: si se repite, es la respuesta guardada
Endpoint::from(__FILE__)->at('POST /_test/p33/idem')->group('public')->idempotent()
    ->expects(['name' => 'required|string'])
    ->handle(fn (array $in) => Response::created(['name' => $in['name'], 'n' => bin2hex(random_bytes(8))]));

Endpoint::from(__FILE__)->at('POST /_test/p33/idem-user')->group('protected')->idempotent()
    ->expects(['name' => 'required|string'])
    ->handle(fn (array $in) => Response::created(['by' => Auth::id($in), 'n' => bin2hex(random_bytes(8))]));

Endpoint::from(__FILE__)->at('POST /_test/p33/report')->group('protected')->asyncable()
    ->expects(['year' => 'required|integer|min:2000'])
    ->handle(function (array $in) {
        Job::progress(50);
        return Response::ok(['year' => $in['year'], 'by' => Auth::id($in)]);
    });

Endpoint::from(__FILE__)->at('POST /_test/p33/forced')->group('public')->async()
    ->handle(fn () => Response::ok(['done' => true]));
