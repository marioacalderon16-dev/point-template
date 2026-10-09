<?php

/** Ejecuta `point call` en la raíz del proyecto; $stdin null = sin datos. Devuelve [salida, código]. */
function p31Call(string $args, ?string $stdin = null): array
{
    $proc = proc_open('php point call ' . $args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    if ($stdin !== null) {
        fwrite($pipes[0], $stdin);
    }
    fclose($pipes[0]);
    $out = trim(stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [$out, proc_close($proc)];
}

/** Conversación MCP: envía los mensajes por stdin y devuelve las respuestas indexadas por id. */
function p31Mcp(array $messages, string $args = '--as=5'): array
{
    $proc = proc_open('php point mcp ' . $args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    foreach ($messages as $m) {
        fwrite($pipes[0], json_encode(['jsonrpc' => '2.0'] + $m) . "\n");
    }
    fclose($pipes[0]);
    $responses = [];
    while (($line = fgets($pipes[1])) !== false) {
        $r = json_decode($line, true);
        $responses[$r['id']] = $r;
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return $responses;
}

test('call: ejecuta por nombre con parámetros de ruta y devuelve 0 en 2xx', function () {
    [$out, $code] = p31Call("p31.item id=7 q=hola --data");
    expect($code)->toBe(0);
    expect(json_decode($out, true))->toBe(['id' => 7, 'q' => 'hola']);
});

test('call: valida igual que por HTTP (422 → código 1) y 5xx → código 2', function () {
    [$out, $code] = p31Call("p31.item id=0");
    expect($code)->toBe(1);
    expect($out)->toContain('422');
    [, $code5] = p31Call("'GET /_test/p28/onerror'");
    expect($code5)->toBe(2);
});

test('call: --as y --role pasan por la auth real', function () {
    [, $noToken] = p31Call("'GET /_test/role/staff'");
    expect($noToken)->toBe(1);
    [, $wrongRole] = p31Call("'GET /_test/role/staff' --as=3 --role=user");
    expect($wrongRole)->toBe(1);
    [$out, $ok] = p31Call("'GET /_test/role/staff' --as=3 --role=editor --data");
    expect($ok)->toBe(0);
    expect(json_decode($out, true))->toBe(['user' => 3]);
});

test('call: cuerpo JSON por stdin, campo=valor tiene prioridad y las claves _ se descartan', function () {
    [$out, $code] = p31Call("p31.create name=Cuaderno tags='[\"x\"]' --as=4 --data", '{"name":"Lapiz","_user_id":99}');
    expect($code)->toBe(0);
    expect(json_decode($out, true))->toBe(['name' => 'Cuaderno', 'tags' => ['x'], 'by' => 4]);
});

test('call: ruta inexistente o parámetro de ruta ausente dan error claro', function () {
    [$out, $code] = p31Call('no.existe');
    expect($code)->toBe(1);
    expect($out)->toContain("No existe ninguna ruta con nombre 'no.existe'");
    [$out2, $code2] = p31Call('p31.item');
    expect($code2)->toBe(1);
    expect($out2)->toContain('Faltan parámetros de ruta: id');
});

test('mcp: initialize y tools/list exponen solo las rutas con ->mcp() y su esquema', function () {
    $r = p31Mcp([
        ['id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']],
        ['method' => 'notifications/initialized'],
        ['id' => 2, 'method' => 'tools/list'],
    ]);
    expect($r[1]['result']['protocolVersion'])->toBe('2025-06-18');
    $tools = array_column($r[2]['result']['tools'], null, 'name');
    expect(array_keys($tools))->toBe(['p31_item', 'p31_create']);
    expect($tools['p31_item']['inputSchema']['properties']['id']['type'])->toBe('integer');
    expect($tools['p31_item']['inputSchema']['required'])->toBe(['id']);
    expect($tools['p31_item']['annotations']['readOnlyHint'])->toBeTrue();
    expect($tools['p31_create']['annotations']['readOnlyHint'])->toBeFalse();
});

test('mcp: tools/call ejecuta como el usuario de --as y marca isError en 4xx', function () {
    $r = p31Mcp([
        ['id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'p31_create', 'arguments' => ['name' => 'Lapiz']]],
        ['id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'p31_create', 'arguments' => []]],
        ['id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'p31_delete', 'arguments' => ['id' => 1]]],
        ['id' => 4, 'method' => 'otro'],
    ]);
    expect($r[1]['result']['isError'])->toBeFalse();
    expect(json_decode($r[1]['result']['content'][0]['text'], true)['data']['by'])->toBe(5);
    expect($r[2]['result']['isError'])->toBeTrue();
    expect($r[2]['result']['content'][0]['text'])->toContain('obligatorio');
    expect($r[3]['error']['code'])->toBe(-32602);
    expect($r[4]['error']['code'])->toBe(-32601);
});

test('mcp: sin --as, una herramienta protegida responde 401 como error', function () {
    $r = p31Mcp([['id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'p31_create', 'arguments' => ['name' => 'x']]]], '');
    expect($r[1]['result']['isError'])->toBeTrue();
    expect($r[1]['result']['content'][0]['text'])->toContain('401');
});
