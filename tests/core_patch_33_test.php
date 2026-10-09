<?php

function p33Bearer(int $sub): array
{
    return ['Authorization' => 'Bearer ' . Core\Auth::issue(['sub' => $sub])];
}

function p33Key(): string
{
    return 'k-' . bin2hex(random_bytes(6));
}

/** Simula el worker: ejecuta el job encolado en un subproceso sin rutas (POINT_NO_ROUTES) y lo quita de la cola. */
function p33RunQueued(string $jobId): string
{
    $root = dirname(__DIR__);
    foreach (glob("$root/storage/jobs/*.json") as $file) {
        $job = json_decode((string) file_get_contents($file), true);
        if (($job['data']['job_id'] ?? null) !== $jobId) {
            continue;
        }
        unlink($file);
        $code = "require 'vendor/autoload.php'; define('POINT_NO_ROUTES', true); require 'bootstrap.php'; "
            . '(new Core\AsyncEndpointJob())->handle(json_decode(getenv("P33_PAYLOAD"), true)); echo "OK";';
        return (string) shell_exec('cd ' . escapeshellarg($root) . ' && env P33_PAYLOAD=' . escapeshellarg(json_encode($job['data']))
            . ' php -r ' . escapeshellarg($code) . ' 2>&1');
    }
    return 'job no encontrado en la cola';
}

test('idempotent: la misma clave devuelve la misma respuesta sin ejecutar otra vez', function () {
    $key = p33Key();
    $a = http('POST', '/_test/p33/idem', ['name' => 'Ana'], ['Idempotency-Key' => $key]);
    $b = http('POST', '/_test/p33/idem', ['name' => 'Ana'], ['Idempotency-Key' => $key]);
    expect($a->status)->toBe(201);
    expect($b->status)->toBe(201);
    expect($b->body['data']['n'])->toBe($a->body['data']['n']);
    expect($b->headers['idempotent-replayed'] ?? null)->toBe('true');
    // Sin clave, o con otra, se ejecuta de nuevo
    $c = http('POST', '/_test/p33/idem', ['name' => 'Ana']);
    expect($c->body['data']['n'])->not()->toBe($a->body['data']['n']);
});

test('idempotent: misma clave con otros datos da 422', function () {
    $key = p33Key();
    http('POST', '/_test/p33/idem', ['name' => 'Ana'], ['Idempotency-Key' => $key]);
    $res = http('POST', '/_test/p33/idem', ['name' => 'Luis'], ['Idempotency-Key' => $key]);
    expect($res->status)->toBe(422);
    expect($res->body['message'])->toContain('otros datos');
});

test('idempotent: la clave es por usuario', function () {
    $key = p33Key();
    $u1 = http('POST', '/_test/p33/idem-user', ['name' => 'x'], ['Idempotency-Key' => $key] + p33Bearer(1));
    $u2 = http('POST', '/_test/p33/idem-user', ['name' => 'x'], ['Idempotency-Key' => $key] + p33Bearer(2));
    expect($u2->body['data']['by'])->toBe(2);
    expect($u2->body['data']['n'])->not()->toBe($u1->body['data']['n']);
});

test('idempotent: con la misma clave en curso responde 409', function () {
    $key = p33Key();
    // Mismo cálculo que Endpoint::idempotencyBegin para ruta pública sin usuario
    $lockKey = 'idem:' . sha1('POST /_test/p33/idem null ' . $key);
    $file = dirname(__DIR__) . '/storage/locks/' . sha1($lockKey) . '.lock';
    @mkdir(dirname($file), 0755, true);
    $h = fopen($file, 'c');
    flock($h, LOCK_EX);
    try {
        $res = http('POST', '/_test/p33/idem', ['name' => 'Ana'], ['Idempotency-Key' => $key]);
        expect($res->status)->toBe(409);
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
});

test('async: sin Prefer responde como siempre; con Prefer: respond-async da 202 y ticket', function () {
    $normal = http('POST', '/_test/p33/report', ['year' => 2025], p33Bearer(7));
    expect($normal->status)->toBe(200);
    expect($normal->body['data']['year'])->toBe(2025);

    $res = http('POST', '/_test/p33/report', ['year' => 2025], ['Prefer' => 'respond-async'] + p33Bearer(7));
    expect($res->status)->toBe(202);
    expect($res->body['state'])->toBe('queued');
    expect($res->headers['location'] ?? '')->toBe('/jobs/' . $res->body['job']);
    expect($res->headers['preference-applied'] ?? '')->toBe('respond-async');

    $id = $res->body['job'];
    expect(http('GET', "/jobs/$id", [], p33Bearer(7))->body['state'])->toBe('queued');

    expect(p33RunQueued($id))->toBe('OK');
    $done = http('GET', "/jobs/$id", [], p33Bearer(7));
    expect($done->body['state'])->toBe('done');
    expect($done->body['progress'])->toBe(50);
    expect($done->body['result_status'])->toBe(200);
    expect($done->body['result']['data'])->toBe(['year' => 2025, 'by' => 7]);
});

test('async: validación y permisos antes de encolar; solo el dueño ve el trabajo', function () {
    expect(http('POST', '/_test/p33/report', ['year' => 1990], ['Prefer' => 'respond-async'] + p33Bearer(7))->status)->toBe(422);
    expect(http('POST', '/_test/p33/report', ['year' => 2025], ['Prefer' => 'respond-async'])->status)->toBe(401);

    $id = http('POST', '/_test/p33/report', ['year' => 2024], ['Prefer' => 'respond-async'] + p33Bearer(7))->body['job'];
    expect(http('GET', "/jobs/$id", [], p33Bearer(8))->status)->toBe(404);
    expect(http('GET', "/jobs/$id")->status)->toBe(404);
    expect(http('GET', "/jobs/$id", [], p33Bearer(7))->status)->toBe(200);
    p33RunQueued($id);
});

test('async: ->async() encola siempre; /jobs con id inexistente o inválido', function () {
    $res = http('POST', '/_test/p33/forced');
    expect($res->status)->toBe(202);
    p33RunQueued($res->body['job']);
    expect(http('GET', '/jobs/' . $res->body['job'])->body['result']['data'])->toBe(['done' => true]);
    expect(http('GET', '/jobs/' . str_repeat('a', 32))->status)->toBe(404);
    expect(http('GET', '/jobs/no-valido')->status)->toBe(422);
});

test('async: con QUEUE_SYNC=true se ejecuta al momento (desarrollo sin worker)', function () {
    $proc = proc_open('env QUEUE_SYNC=true php point call "POST /_test/p33/forced"', [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    $out = json_decode((string) stream_get_contents($pipes[1]), true);
    proc_close($proc);
    expect($out['status'])->toBe(202);
    expect($out['state'])->toBe('done');
});
