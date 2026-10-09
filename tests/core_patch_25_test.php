<?php

use Core\Validator;

function p25Get(string $path, array $claims): object
{
    $token = Core\Auth::issue($claims);
    return http('GET', $path, [], ['Authorization' => "Bearer $token"]);
}

function p25Throws(callable $fn): bool
{
    try {
        $fn();
    } catch (InvalidArgumentException) {
        return true;
    }
    return false;
}

test('parche 25: una regla mal escrita falla cerrado (500) en vez de no validar', function () {
    $res = http('POST', '/_test/p25/typo', ['email' => 'no-es-email']);
    expect($res->status)->toBe(500);
    expect(json_encode($res->body))->not()->toContain('no debería llegar');
});

test('parche 25: las reglas de Rules::custom() se registran al arrancar (slug)', function () {
    expect(http('POST', '/_test/p25/slug', ['slug' => 'mi-post-2026'])->status)->toBe(200);
    expect(http('POST', '/_test/p25/slug', ['slug' => 'Mi Post'])->status)->toBe(422);
});

test('parche 25: extend registra una regla con parámetro y mensaje propio', function () {
    Validator::extend('multiple_of', fn ($v, $p) => (int) $v % (int) $p === 0, ':field debe ser múltiplo de 5');
    $ok = new Validator(['n' => 10], ['n' => 'required|integer|multiple_of:5']);
    $bad = new Validator(['n' => 7], ['n' => 'required|integer|multiple_of:5']);
    expect($ok->passes())->toBeTrue();
    expect($bad->passes())->toBeFalse();
    expect($bad->errors()['n'][0])->toBe('n debe ser múltiplo de 5');
});

test('parche 25: extend no permite redefinir reglas nativas ni nombres inválidos', function () {
    expect(p25Throws(fn () => Validator::extend('email', fn () => true)))->toBeTrue();
    expect(p25Throws(fn () => Validator::extend('Mal Nombre', fn () => true)))->toBeTrue();
});

test('parche 25: after_or_equal compara con otro campo', function () {
    expect(http('POST', '/_test/p25/range', ['from' => '2026-10-01', 'to' => '2026-10-10'])->status)->toBe(200);
    expect(http('POST', '/_test/p25/range', ['from' => '2026-10-01', 'to' => '2026-10-01'])->status)->toBe(200);
    expect(http('POST', '/_test/p25/range', ['from' => '2026-10-10', 'to' => '2026-10-01'])->status)->toBe(422);
});

test('parche 25: gte compara números con otro campo', function () {
    $base = ['from' => '2026-10-01', 'to' => '2026-10-02'];
    expect(http('POST', '/_test/p25/range', $base + ['min' => 5, 'max' => 9])->status)->toBe(200);
    expect(http('POST', '/_test/p25/range', $base + ['min' => 9, 'max' => 5])->status)->toBe(422);
});

test('parche 25: gt/lt/before/after aceptan un literal', function () {
    expect((new Validator(['n' => 3], ['n' => 'integer|gt:2|lt:4']))->passes())->toBeTrue();
    expect((new Validator(['n' => 4], ['n' => 'integer|lt:4']))->passes())->toBeFalse();
    expect((new Validator(['d' => '2026-01-01'], ['d' => 'date|before:2027-01-01|after:2025-12-31']))->passes())->toBeTrue();
    expect((new Validator(['d' => '2026-01-01'], ['d' => 'date|before:2026-01-01']))->passes())->toBeFalse();
});

test('parche 25: claim roles (lista) da acceso si contiene un rol permitido', function () {
    expect(p25Get('/_test/p25/billing', ['sub' => 7, 'roles' => ['editor', 'billing']])->status)->toBe(200);
    expect(p25Get('/_test/p25/billing', ['sub' => 7, 'roles' => ['editor']])->status)->toBe(403);
    expect(p25Get('/_test/role/staff', ['sub' => 7, 'roles' => ['editor', 'billing']])->status)->toBe(200);
});

test('parche 25: el claim role (texto) sigue funcionando y se combina con roles', function () {
    expect(p25Get('/_test/p25/billing', ['sub' => 7, 'role' => 'billing'])->status)->toBe(200);
    expect(p25Get('/_test/p25/billing', ['sub' => 7, 'role' => 'admin', 'roles' => ['billing']])->status)->toBe(200);
    expect(p25Get('/_test/p25/billing', ['sub' => 7, 'roles' => 'billing'])->status)->toBe(403);
});
