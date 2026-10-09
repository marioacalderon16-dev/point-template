<?php

/** Ejecuta un comando de point en $dir y devuelve [salida, código]. */
function pointIn(string $dir, string $args, string $env = ''): array
{
    exec('cd ' . escapeshellarg($dir) . " && $env php " . escapeshellarg(dirname(__DIR__) . '/point') . " $args 2>&1", $out, $code);
    return [implode("\n", $out), $code];
}

test('make:action crea services/Actions/<Nombre>.php invocable y sin HTTP', function () {
    $dir = sys_get_temp_dir() . '/point_makeaction_' . bin2hex(random_bytes(4));
    mkdir($dir);
    try {
        [$out, $code] = pointIn($dir, 'make:action create-user');
        expect($code)->toBe(0);
        $file = "$dir/services/Actions/CreateUser.php";
        expect(file_exists($file))->toBeTrue();
        exec('php -l ' . escapeshellarg($file), $lint, $lintCode);
        expect($lintCode)->toBe(0);

        require_once $file;
        $action = new App\Services\Actions\CreateUser();
        expect($action(['name' => 'Ana']))->toBe(['name' => 'Ana']);
        expect(App\Services\Actions\CreateUser::rules())->toBe([]);

        [, $again] = pointIn($dir, 'make:action CreateUser');
        expect($again)->toBe(1);
        [, $noName] = pointIn($dir, 'make:action');
        expect($noName)->toBe(1);
    } finally {
        shell_exec('rm -rf ' . escapeshellarg($dir));
    }
});

test('make:http genera peticiones con token en rutas protegidas, cuerpo de ejemplo y parámetros', function () {
    $file = sys_get_temp_dir() . '/point_http_' . bin2hex(random_bytes(4)) . '.http';
    try {
        [$out, $code] = pointIn(dirname(__DIR__), 'make:http --output=' . escapeshellarg($file), 'POINT_TESTING=1');
        expect($code)->toBe(0);
        $http = (string) file_get_contents($file);

        expect($http)->toContain('@baseUrl = http://localhost:');
        // Pública sin token; protegida con token
        expect($http)->toContain("### GET /health (health · public)\nGET {{baseUrl}}/health");
        expect($http)->not()->toContain("{{baseUrl}}/health\nAuthorization");
        expect($http)->toContain("GET {{baseUrl}}/_test/role/staff\nAuthorization: Bearer {{token}}");
        // Cuerpo con valores de ejemplo según las reglas
        expect($http)->toContain('"email": "ana@example.com"');
        expect($http)->toContain('"status": "draft"');
        // Parámetro de ruta: {id} → 1 en la petición (el título conserva la ruta original)
        expect($http)->toContain("### GET /_test/p31/items/{id} (p31.item · public)\nGET {{baseUrl}}/_test/p31/items/1");

        [, $exists] = pointIn(dirname(__DIR__), 'make:http --output=' . escapeshellarg($file), 'POINT_TESTING=1');
        expect($exists)->toBe(1);
        [, $forced] = pointIn(dirname(__DIR__), 'make:http --force --output=' . escapeshellarg($file), 'POINT_TESTING=1');
        expect($forced)->toBe(0);
    } finally {
        @unlink($file);
    }
});
