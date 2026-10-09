<?php

test('parche 1: el status del body se envía como código HTTP', function () {
    $res = http('GET', '/_test/error');
    expect($res->status)->toBe(409);
});

test('parche 1: validación fallida devuelve 422 real', function () {
    $res = http('POST', '/_test/user', []);
    expect($res->status)->toBe(422);
});

test('parche 1: 204 sin cuerpo', function () {
    $res = http('GET', '/_test/empty');
    expect($res->status)->toBe(204);
});

test('parche 2: string se responde como texto plano', function () {
    $res = http('GET', '/_test/text');
    expect($res->status)->toBe(200);
    expect($res->body)->toBe('challenge-123');
    expect($res->headers['content-type'])->toContain('text/plain');
});

test('parche 3: expects no elimina datos del middleware', function () {
    $res = http('POST', '/_test/user', ['name' => 'Ana', 'extra' => 'x']);
    expect($res->status)->toBe(200);
    expect($res->body['data']['_user_id'])->toBe(42);
    expect($res->body['data']['name'])->toBe('Ana');
    expect($res->body['data'])->not()->toHaveKey('extra');
});

test('parche 4: middlewares globales se ejecutan una sola vez', function () {
    $res = http('GET', '/_test/global-runs');
    expect($res->body['data']['runs'])->toBe(1);
});

test('health responde 200', function () {
    $res = http('GET', '/health');
    expect($res->status)->toBe(200);
});
