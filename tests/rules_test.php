<?php

use App\Services\Rules;
use Core\Validator;

enum RulesTestStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

$valid = ['id' => 5, 'status' => 'draft', 'email' => ' Ana@Example.com '];

test('Rules: datos válidos pasan y se normalizan', function () use ($valid) {
    $res = http('POST', '/_test/rules', $valid);
    expect($res->status)->toBe(200);
    expect($res->body['data']['email'])->toBe('ana@example.com');
    expect($res->body['data']['page'])->toBe(1);
    expect($res->body['data']['limit'])->toBe(20);
});

test('Rules: id 0, negativo o decimal da 422', function () use ($valid) {
    foreach ([0, -1, '1.5', 'abc'] as $id) {
        expect(http('POST', '/_test/rules', ['id' => $id] + $valid)->status)->toBe(422);
    }
});

test('Rules: estado fuera de la lista da 422', function () use ($valid) {
    expect(http('POST', '/_test/rules', ['status' => 'borrado'] + $valid)->status)->toBe(422);
});

test('Rules: uuid opcional inválido da 422 y limit por encima del máximo da 422', function () use ($valid) {
    expect(http('POST', '/_test/rules', ['uuid' => 'no-es-uuid'] + $valid)->status)->toBe(422);
    expect(http('POST', '/_test/rules', ['limit' => 101] + $valid)->status)->toBe(422);
});

test('Rules: status acepta un enum respaldado', function () {
    $rules = ['s' => Rules::status(RulesTestStatus::class)];
    expect((new Validator(['s' => 'published'], $rules))->passes())->toBeTrue();
    expect((new Validator(['s' => 'otro'], $rules))->passes())->toBeFalse();
});

test('Rules: status rechaza valores con coma o lista vacía', function () {
    foreach ([['a,b'], [], 'NoEsEnum'] as $bad) {
        $thrown = false;
        try {
            Rules::status($bad);
        } catch (InvalidArgumentException) {
            $thrown = true;
        }
        expect($thrown)->toBeTrue();
    }
});

test('Rules: text, boolean y date validan', function () {
    $rules = ['t' => Rules::text(max: 5), 'b' => Rules::boolean(), 'd' => Rules::date()];
    expect((new Validator(['t' => 'hola', 'b' => 'true', 'd' => '2026-10-08'], $rules))->passes())->toBeTrue();
    expect((new Validator(['t' => 'demasiado', 'b' => 'true', 'd' => '2026-10-08'], $rules))->passes())->toBeFalse();
    expect((new Validator(['t' => 'hola', 'b' => 'quizá', 'd' => '08/10/2026'], $rules))->passes())->toBeFalse();
});

test('grupo staff: sin token da 401', function () {
    expect(http('GET', '/_test/role/staff')->status)->toBe(401);
});

test('grupo staff: rol no permitido da 403', function () {
    $token = Core\Auth::issue(['sub' => 1, 'role' => 'user']);
    expect(http('GET', '/_test/role/staff', [], ['Authorization' => "Bearer $token"])->status)->toBe(403);
});

test('grupo staff: editor y admin entran', function () {
    foreach (['editor', 'admin'] as $role) {
        $token = Core\Auth::issue(['sub' => 9, 'role' => $role]);
        $res = http('GET', '/_test/role/staff', [], ['Authorization' => "Bearer $token"]);
        expect($res->status)->toBe(200);
        expect($res->body['data']['user'])->toBe(9);
    }
});
