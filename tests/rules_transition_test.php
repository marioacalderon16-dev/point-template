<?php

use App\Services\Rules;

const P32_STEPS = [
    'pending'   => ['paid', 'cancelled'],
    'paid'      => ['shipped'],
    'shipped'   => ['delivered'],
    'delivered' => [],
    'cancelled' => [],
];

/**
 * Valida $cases (id → nuevo estado) contra una base SQLite temporal con pedidos en varios estados,
 * en un subproceso (conexión propia). Devuelve, por caso, true o el primer mensaje de error.
 */
function p32Validate(array $cases): array
{
    $db = sys_get_temp_dir() . '/p32_' . bin2hex(random_bytes(4)) . '.sqlite';
    $pdo = new PDO("sqlite:$db");
    $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, status TEXT)');
    $pdo->exec("INSERT INTO orders (id, status) VALUES (1, 'pending'), (2, 'paid'), (3, 'delivered'), (4, 'perdido')");
    $code = "require 'vendor/autoload.php'; \$_ENV = getenv(); \$steps = " . var_export(P32_STEPS, true) . ";"
        . "\$out = []; foreach (json_decode(getenv('P32_CASES'), true) as \$i => [\$id, \$to]) {"
        . " \$v = new Core\\Validator(['id' => \$id, 'status' => \$to], ['id' => 'required|integer', 'status' => App\\Services\\Rules::transition('orders', \$steps)]);"
        . " \$out[\$i] = \$v->passes() ? true : \$v->errors()['status'][0]; } echo json_encode(\$out);";
    try {
        $cmd = 'cd ' . escapeshellarg(dirname(__DIR__)) . ' && env DB_DSN=' . escapeshellarg("sqlite:$db")
            . ' P32_CASES=' . escapeshellarg(json_encode($cases)) . ' php -r ' . escapeshellarg($code) . ' 2>&1';
        $raw = (string) shell_exec($cmd);
        return json_decode($raw, true) ?? ['raw' => $raw];
    } finally {
        @unlink($db);
    }
}

test('transition: admite los cambios permitidos desde el estado actual y explica los que no', function () {
    $r = p32Validate([
        [1, 'paid'],       // pending → paid
        [1, 'shipped'],    // pending → shipped (no)
        [2, 'shipped'],    // paid → shipped
        [3, 'cancelled'],  // delivered es final
        [1, 'volando'],    // estado desconocido
        [4, 'paid'],       // estado actual fuera del flujo
        [999, 'paid'],     // no existe: la regla no decide
    ]);
    expect($r[0])->toBeTrue();
    expect($r[1])->toBe("No se puede pasar de 'pending' a 'shipped'. Permitidos: paid, cancelled.");
    expect($r[2])->toBeTrue();
    expect($r[3])->toBe("'delivered' es un estado final: no admite cambios.");
    expect($r[4])->toContain('debe ser uno de');
    expect($r[5])->toBe("El estado actual 'perdido' no está en el flujo.");
    expect($r[6])->toBeTrue();
});

test('transition: un mapa incoherente falla al definir la regla', function () {
    foreach ([[], ['a' => ['b']], ['a,b' => []], ['a' => 'b']] as $bad) {
        $thrown = false;
        try {
            Rules::transition('orders', $bad);
        } catch (InvalidArgumentException) {
            $thrown = true;
        }
        expect($thrown)->toBeTrue();
    }
    $thrown = false;
    try {
        Rules::transition('orders; DROP TABLE x', P32_STEPS);
    } catch (InvalidArgumentException) {
        $thrown = true;
    }
    expect($thrown)->toBeTrue();
});

test('canTransition y diagram usan el mismo mapa', function () {
    expect(Rules::canTransition(P32_STEPS, 'paid', 'shipped'))->toBeTrue();
    expect(Rules::canTransition(P32_STEPS, 'pending', 'shipped'))->toBeFalse();
    expect(Rules::canTransition(P32_STEPS, 'otro', 'paid'))->toBeFalse();
    $diagram = Rules::diagram(P32_STEPS);
    expect($diagram)->toContain("stateDiagram-v2\n  pending --> paid\n  pending --> cancelled\n");
    expect($diagram)->toContain('  delivered --> [*]');
});

test('una regla propia puede devolver su propio mensaje de error', function () {
    Core\Validator::extend('even_p32', fn ($v) => $v % 2 === 0 ?: "{$v} no es par");
    $v = new Core\Validator(['n' => 3], ['n' => 'integer|even_p32']);
    expect($v->passes())->toBeFalse();
    expect($v->errors()['n'][0])->toBe('3 no es par');
});
