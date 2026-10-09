<?php

/** POST JSON contra un servidor propio con APP_STRICT_MODE dado (el compartido de http() no lo
 *  tiene): devuelve [status, body decodificado]. */
function postWithStrictMode(string $strict, string $path, array $data): array
{
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);

    $env = array_merge(getenv(), ['APP_STRICT_MODE' => $strict]);
    $null = ['file', '/dev/null', 'w'];
    $proc = proc_open("exec php -S 127.0.0.1:$port index.php", [['file', '/dev/null', 'r'], $null, $null], $pipes, getcwd(), $env);

    try {
        for ($i = 0; $i < 50 && !($fp = @fsockopen('127.0.0.1', $port, $e, $s, 0.1)); $i++) usleep(100000);
        if (!empty($fp)) fclose($fp);

        $body = @file_get_contents("http://127.0.0.1:$port$path", false, stream_context_create(['http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => json_encode($data),
            'ignore_errors' => true,
        ]]));
        preg_match('/^HTTP\/\S+\s+(\d+)/', http_get_last_response_headers()[0] ?? '', $m);
        return [(int) ($m[1] ?? 0), json_decode((string) $body, true)];
    } finally {
        proc_terminate($proc);
        proc_close($proc);
    }
}

test('parche 11: APP_STRICT_MODE=true (texto) no provoca TypeError y acepta campos declarados', function () {
    [$status, $body] = postWithStrictMode('true', '/_test/user', ['name' => 'Ana']);
    expect($status)->toBe(200);
    expect($body['data']['name'])->toBe('Ana');
    expect($body['data']['_user_id'])->toBe(42); // los datos de middleware no cuentan como extra
});

test('parche 11: modo estricto rechaza campos del cuerpo no declarados con 422', function () {
    [$status, $body] = postWithStrictMode('true', '/_test/user', ['name' => 'Ana', 'extra' => 'x']);
    expect($status)->toBe(422);
    expect($body['errors'])->toHaveKey('extra');
});

test('parche 11: APP_STRICT_MODE=false (texto) filtra los extra sin rechazar', function () {
    [$status, $body] = postWithStrictMode('false', '/_test/user', ['name' => 'Ana', 'extra' => 'x']);
    expect($status)->toBe(200);
    expect($body['data'])->not()->toHaveKey('extra');
});
