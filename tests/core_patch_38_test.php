<?php

require_once __DIR__ . '/support/postgres.php';

use Core\QueryBuilder;
use Core\Scribe\Seeder;
use Core\Scribe\Table;

test('parche 38: booleanos en Postgres (false llegaba como \'\' y Postgres lo rechazaba)', function () {
    withFreshPostgres(function (string $dsn, string $user, string $pass) {
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec(Table::create('p38')->id()->str('nombre')->bool('activo')->default(true)->toSql('pgsql'));

        $fila = QueryBuilder::table('p38', $pdo)->insertReturning(['nombre' => 'a', 'activo' => false], 'id, activo');
        expect($fila['activo'])->toBeFalse();

        QueryBuilder::table('p38', $pdo)->insert(['nombre' => 'b', 'activo' => true]);
        QueryBuilder::table('p38', $pdo)->where('nombre', 'b')->update(['activo' => false]);
        expect(QueryBuilder::table('p38', $pdo)->where('activo', false)->count())->toBe(2);
        expect(count(QueryBuilder::table('p38', $pdo)->raw('SELECT id FROM p38 WHERE activo = ?', [false])))->toBe(2);

        (new Seeder($pdo))->insert('p38', [['nombre' => 'c', 'activo' => false]]);
        expect(QueryBuilder::table('p38', $pdo)->where('nombre', 'c')->first()['activo'])->toBeFalse();
    });
});

test('parche 38: en sqlite el id se numera solo (antes AUTO_INCREMENT de MySQL dejaba id NULL)', function () {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec(Table::create('p38')->id()->str('nombre')->bool('activo')->default(true)->toSql('sqlite'));

    QueryBuilder::table('p38', $pdo)->insert(['nombre' => 'a', 'activo' => false]);
    QueryBuilder::table('p38', $pdo)->insert(['nombre' => 'b']);
    $ids = array_column(QueryBuilder::table('p38', $pdo)->orderBy('id')->get(), 'id');
    expect($ids)->toEqual([1, 2]);
});

test('parche 38: las tablas de control de migrate y seed son válidas en sqlite', function () {
    $dir = sys_get_temp_dir() . '/p38_' . bin2hex(random_bytes(3));
    mkdir($dir);
    shell_exec('env -u POINT_TESTING DB_DSN=' . escapeshellarg("sqlite:$dir/db.sqlite") . ' php point migrate 2>&1');

    $pdo = new PDO("sqlite:$dir/db.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    new Core\Scribe\SeedRunner($pdo, $dir);   // crea la tabla seeds al construirse
    $ddl = $pdo->query("SELECT name, sql FROM sqlite_master WHERE name IN ('migrations', 'seeds')")->fetchAll(PDO::FETCH_KEY_PAIR);
    expect($ddl['migrations'])->toContain('INTEGER PRIMARY KEY AUTOINCREMENT');
    expect($ddl['seeds'])->toContain('INTEGER PRIMARY KEY AUTOINCREMENT');
    array_map('unlink', glob("$dir/*"));
    rmdir($dir);
});
