<?php

use Core\Validator;

function p37Errors(array $data): array
{
    $res = http('POST', '/_test/p37/users', $data);
    expect($res->status)->toBe(422);
    return $res->body['errors'];
}

test('messages: sustituye el mensaje de una regla concreta (campo.regla)', function () {
    $e = p37Errors(['email' => 'no-es-correo']);
    expect($e['name'])->toBe(['Escribe tu nombre.']);
    expect($e['email'])->toBe(['Ese correo no parece válido.']);
});

test('messages: "campo" sirve para cualquier regla y :field se cambia por el nombre', function () {
    expect(p37Errors(['name' => 'Ana', 'email' => 'a@b.com', 'age' => 'abc'])['age'])->toBe(['Revisa la edad (entre 18 y 99).']);
    expect(p37Errors(['name' => 'Ana', 'email' => 'a@b.com', 'age' => 10])['age'])->toBe(['Revisa la edad (entre 18 y 99).']);
    expect(p37Errors(['name' => 'A', 'email' => 'a@b.com'])['name'])->toBe(['El nombre (name) es demasiado corto.']);
});

test('messages: vale para los elementos de each y para las reglas propias', function () {
    $e = p37Errors(['name' => 'Ana', 'email' => 'a@b.com', 'tags' => [1, 'x'], 'slug' => 'Con Espacios']);
    expect($e['tags.1'])->toBe(['Las etiquetas deben ser números.']);
    expect($e['slug'])->toBe(['El enlace solo admite minúsculas y guiones.']);
});

test('messages: las reglas sin mensaje propio conservan el de Point y las claves siguen siendo técnicas', function () {
    $e = p37Errors(['name' => 'Ana', 'email' => 'a@b.com', 'bio' => 'demasiado larga']);
    expect(array_keys($e))->toBe(['bio']);
    expect($e['bio'][0])->toContain('bio');
    expect(http('POST', '/_test/p37/users', ['name' => 'Ana', 'email' => 'a@b.com'])->status)->toBe(200);
});

test('messages: también en Endpoint::resource y en el Validator directo', function () {
    $res = http('POST', '/_test/p37/res', []);
    expect($res->status)->toBe(422);
    expect($res->body['errors']['title'])->toBe(['Ponle un título.']);

    $v = new Validator(['n' => 'x'], ['n' => 'required|integer'], [], false, ['n.integer' => 'Debe ser un número.']);
    expect($v->passes())->toBeFalse();
    expect($v->errors()['n'])->toBe(['Debe ser un número.']);
});
