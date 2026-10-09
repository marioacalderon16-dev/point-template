<?php

function p26Me(array $claims): object
{
    $token = Core\Auth::issue($claims);
    return http('GET', '/_test/p26/me', [], ['Authorization' => "Bearer $token"]);
}

test('parche 26: Auth::id, roles y hasRole leen el usuario autenticado', function () {
    $res = p26Me(['sub' => 42, 'role' => 'editor', 'roles' => ['billing']]);
    expect($res->status)->toBe(200);
    expect($res->body['data']['id'])->toBe(42);
    expect($res->body['data']['roles'])->toBe(['editor', 'billing']);
    expect($res->body['data']['isAdmin'])->toBeFalse();
    expect($res->body['data']['isStaff'])->toBeTrue();
});

test('parche 26: Auth::id en una ruta sin auth da error claro (500) en vez de null', function () {
    $res = http('GET', '/_test/p26/public');
    expect($res->status)->toBe(500);
});

test('parche 26: Auth::rolesFromClaims ignora valores que no son texto', function () {
    expect(Core\Auth::rolesFromClaims(['role' => 'a', 'roles' => ['b', 3, null]]))->toBe(['a', 'b']);
    expect(Core\Auth::rolesFromClaims(['roles' => 'no-es-lista']))->toBe([]);
});

test('routes: muestra acceso (public, token, roles) y marca grupos no definidos', function () {
    $out = (string) shell_exec('cd ' . escapeshellarg(dirname(__DIR__)) . ' && POINT_TESTING=1 php point routes 2>&1');
    $line = function (string $path) use ($out): string {
        foreach (explode("\n", $out) as $row) {
            if (preg_match('#\s' . preg_quote($path, '#') . '\s#', $row)) {
                return $row;
            }
        }
        return '';
    };
    expect($line('/_test/group/public'))->toContain('public');
    expect($line('/_test/group/protected'))->toContain('token');
    expect($line('/_test/role/staff'))->toContain('roles: admin,editor');
    expect($line('/_test/group/undefined'))->toContain("UNDEFINED GROUP 'no_existe'");
    expect($line('/_test/rules'))->toContain('id, uuid, status, email, page, limit');
});

test('make:endpoint genera un archivo válido con Rules, Auth y Response', function () {
    $dir = sys_get_temp_dir() . '/point_makeendpoint_' . bin2hex(random_bytes(4));
    mkdir($dir);
    try {
        exec('cd ' . escapeshellarg($dir) . ' && php ' . escapeshellarg(dirname(__DIR__) . '/point')
            . ' make:endpoint posts/create --post 2>&1', $out, $code);
        expect($code)->toBe(0);
        $file = "$dir/endpoints/posts/create.php";
        exec('php -l ' . escapeshellarg($file), $lint, $lintCode);
        expect($lintCode)->toBe(0);
        $src = (string) file_get_contents($file);
        expect($src)->toContain("->at('POST /posts/create')");
        expect($src)->toContain('Response::ok(');
        expect($src)->toContain('Rules::id()');
        expect($src)->toContain('Auth::id(');
    } finally {
        shell_exec('rm -rf ' . escapeshellarg($dir));
    }
});
