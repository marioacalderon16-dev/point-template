<?php

use App\Services\Rules;
use Core\Endpoint;
use Core\Response;

Endpoint::from(__FILE__)->at('POST /_test/rules')
    ->expects([
        'id'     => Rules::id(),
        'uuid'   => Rules::uuid(required: false),
        'status' => Rules::status(['draft', 'published']),
        'email'  => Rules::email(),
        ...Rules::pagination(),
    ])
    ->handle(fn (array $d) => Response::ok($d));

Endpoint::from(__FILE__)->at('GET /_test/role/staff')->group('staff')
    ->handle(fn (array $d) => Response::ok(['user' => $d['_user_id'] ?? null]));
