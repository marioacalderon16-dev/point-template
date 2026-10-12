<?php

use Core\Endpoint;
use Core\Response;

Endpoint::from(__FILE__)->at('POST /_test/p37/users')->group('public')
    ->expects([
        'name'  => 'required|string|min:2',
        'email' => 'required|email',
        'age'   => 'optional|integer|between:18,99',
        'tags'  => 'optional|array|each:integer',
        'slug'  => 'optional|slug',
        'bio'   => 'optional|string|max:5',
    ])
    ->messages([
        'name.required' => 'Escribe tu nombre.',
        'email.email'   => 'Ese correo no parece válido.',
        'age'           => 'Revisa la edad (entre 18 y 99).',
        'tags.integer'  => 'Las etiquetas deben ser números.',
        'slug.slug'     => 'El enlace solo admite minúsculas y guiones.',
    ])
    ->messages(['name.min' => 'El nombre (:field) es demasiado corto.'])
    ->handle(fn (array $in) => Response::ok($in));

Endpoint::resource('/_test/p37/res')->group('public')
    ->expects(['title' => 'required|string'])
    ->messages(['title.required' => 'Ponle un título.'])
    ->create(fn (array $in) => Response::created($in));
