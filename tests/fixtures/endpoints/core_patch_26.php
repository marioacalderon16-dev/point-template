<?php

use Core\Auth;
use Core\Endpoint;
use Core\Response;

Endpoint::from(__FILE__)->at('GET /_test/p26/me')->group('protected')
    ->handle(fn (array $in) => Response::ok([
        'id'      => Auth::id($in),
        'roles'   => Auth::roles($in),
        'isAdmin' => Auth::hasRole($in, 'admin'),
        'isStaff' => Auth::hasRole($in, 'admin', 'editor'),
    ]));

Endpoint::from(__FILE__)->at('GET /_test/p26/public')->group('public')
    ->handle(fn (array $in) => Response::ok(['id' => Auth::id($in)]));
