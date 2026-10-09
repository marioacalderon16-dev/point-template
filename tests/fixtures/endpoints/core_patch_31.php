<?php

use App\Services\Rules;
use Core\Auth;
use Core\Endpoint;
use Core\Response;

Endpoint::from(__FILE__)->at('GET /_test/p31/items/{id}')->name('p31.item')->group('public')
    ->mcp('Devuelve un item por su ID')
    ->expects(['id' => Rules::id(), 'q' => 'optional|string'])
    ->handle(fn (array $in) => Response::ok(['id' => $in['id'], 'q' => $in['q']]));

Endpoint::from(__FILE__)->at('POST /_test/p31/items')->name('p31.create')->group('protected')
    ->mcp('Crea un item')
    ->expects(['name' => Rules::text(max: 20), 'tags' => 'optional|array'])
    ->handle(fn (array $in) => Response::created(['name' => $in['name'], 'tags' => $in['tags'], 'by' => Auth::id($in)]));

// Sin ->mcp(): no debe aparecer como herramienta
Endpoint::from(__FILE__)->at('DELETE /_test/p31/items/{id}')->name('p31.delete')->group('admin')
    ->handle(fn () => Response::noContent());
