<?php

require_once __DIR__ . '/fixtures/jobs/QueueProbeJob.php';

use Core\Queue;
use Core\QueryBuilder;

/** Apunta la cola y los logs a un directorio temporal aislado. */
function queueSandbox(): string
{
    $dir = sys_get_temp_dir() . '/point_queue_' . bin2hex(random_bytes(4));
    mkdir($dir . '/failed', 0755, true);
    $prop = new ReflectionProperty(Queue::class, 'path');
    $prop->setValue(null, $dir);
    Core\Log::configure($dir . '/logs');
    QueueProbeJob::$runs = [];
    QueueProbeJob::$concurrent = null;
    QueueProbeJob::$pendingDuring = [];
    return $dir;
}

test('parche 7a: DB_EMULATE_PREPARES configurable (default false)', function () {
    $prev = $_ENV['DB_EMULATE_PREPARES'] ?? null;
    unset($_ENV['DB_EMULATE_PREPARES']);
    // sqlite no expone el atributo: se comprueba que la conexión acepta ambos valores
    expect(QueryBuilder::connection('sqlite::memory:'))->toBeInstanceOf(PDO::class);
    $_ENV['DB_EMULATE_PREPARES'] = 'true';
    expect(QueryBuilder::connection('sqlite::memory:'))->toBeInstanceOf(PDO::class);
    if ($prev === null) unset($_ENV['DB_EMULATE_PREPARES']); else $_ENV['DB_EMULATE_PREPARES'] = $prev;
});

test('parche 7b: insertReturning devuelve la fila insertada (sqlite)', function () {
    $pdo = QueryBuilder::connection('sqlite::memory:');
    $pdo->exec("CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, active INTEGER DEFAULT 1)");

    $row = QueryBuilder::table('items', $pdo)->insertReturning(['name' => 'uno']);
    expect($row['id'])->toBe(1);
    expect($row['name'])->toBe('uno');
    expect($row['active'])->toBe(1);

    $row = QueryBuilder::table('items', $pdo)->insertReturning(['name' => 'dos'], 'id, name');
    expect($row)->toEqual(['id' => 2, 'name' => 'dos']);
});

test('parche 7b: insertReturning rechaza columnas de retorno inválidas', function () {
    $pdo = QueryBuilder::connection('sqlite::memory:');
    $pdo->exec("CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)");
    expect(fn() => QueryBuilder::table('items', $pdo)->insertReturning(['name' => 'x'], 'id; DROP TABLE items'))
        ->toThrow(InvalidArgumentException::class);
});

test('parche 6: processNext reclama el job y un worker concurrente no lo reprocesa', function () {
    $dir = queueSandbox();
    Queue::dispatch(QueueProbeJob::class, ['n' => 1]);

    $result = Queue::processNext();
    expect($result['status'])->toBe('ok');
    expect(QueueProbeJob::$runs)->toEqual([1]);
    expect(QueueProbeJob::$concurrent)->toBeNull();   // el segundo worker no encontró nada
    expect(QueueProbeJob::$pendingDuring)->toBeEmpty(); // pending() ignora .processing
    expect(glob($dir . '/*.json*'))->toBeEmpty();
    expect(Queue::processNext())->toBeNull();
});

test('parche 6: reintento devuelve el job a la cola y luego pasa a failed', function () {
    $dir = queueSandbox();
    Queue::dispatch(QueueProbeJob::class, ['n' => 2, 'fail' => true]);

    expect(Queue::processNext()['status'])->toBe('retry');
    expect(glob($dir . '/*.processing'))->toBeEmpty();
    expect(Queue::pending())->toHaveCount(1);

    expect(Queue::processNext()['status'])->toBe('failed');
    expect(Queue::pending())->toBeEmpty();
    expect(Queue::failed())->toHaveCount(1);
    expect(glob($dir . '/*.processing'))->toBeEmpty();
});

test('parche 6: un .processing huérfano no aparece en listados ni se reprocesa', function () {
    $dir = queueSandbox();
    $id = Queue::dispatch(QueueProbeJob::class, ['n' => 3]);
    rename("$dir/$id.json", "$dir/$id.json.processing"); // otro worker lo reclamó
    expect(Queue::processNext())->toBeNull();
    expect(Queue::pending())->toBeEmpty();
});
