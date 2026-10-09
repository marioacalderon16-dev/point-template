<?php

test('parche 17: el grupo protected (default de make:endpoint) exige token', function () {
    $res = http('GET', '/_test/group/protected');
    expect($res->status)->toBe(401);
});

test('parche 17: el grupo protected acepta un token válido', function () {
    $token = Core\Auth::issue(['sub' => 7]);
    $res = http('GET', '/_test/group/protected', [], ['Authorization' => "Bearer $token"]);
    expect($res->status)->toBe(200);
    expect($res->body['data'])->toBe(7);
});

test('parche 17: el grupo public no exige token', function () {
    $res = http('GET', '/_test/group/public');
    expect($res->status)->toBe(200);
});

test('parche 17: un grupo no declarado falla cerrado (500) en vez de saltarse los middlewares', function () {
    $res = http('GET', '/_test/group/undefined');
    expect($res->status)->toBe(500);
    expect(json_encode($res->body))->not()->toContain('no debería llegar');
});
