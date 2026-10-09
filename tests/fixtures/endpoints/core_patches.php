<?php

use Core\Endpoint;
use Core\Response;

Endpoint::registerMiddleware('test_user', fn () => ['_user_id' => 42]);
Endpoint::registerMiddleware('test_count', function () {
    $GLOBALS['__global_mw_runs'] = ($GLOBALS['__global_mw_runs'] ?? 0) + 1;
});
Endpoint::addGlobalMiddleware('test_count');

Endpoint::from(__FILE__)->at('GET /_test/error')
    ->handle(fn () => Response::error('Conflicto', 409));

Endpoint::from(__FILE__)->at('GET /_test/text')
    ->handle(fn () => 'challenge-123');

Endpoint::from(__FILE__)->at('GET /_test/empty')
    ->handle(fn () => Response::noContent());

Endpoint::from(__FILE__)->at('POST /_test/user')
    ->through('test_user')
    ->expects(['name' => 'required|string'])
    ->handle(fn (array $d) => Response::ok($d));

Endpoint::from(__FILE__)->at('GET /_test/global-runs')
    ->handle(fn () => Response::ok(['runs' => $GLOBALS['__global_mw_runs'] ?? 0]));
