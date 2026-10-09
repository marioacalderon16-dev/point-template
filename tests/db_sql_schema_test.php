<?php

require_once __DIR__ . '/support/postgres.php';

test('parche 10: db:sql crea el esquema del search_path en una base nueva', function () {
    withEphemeralPostgres(function (string $dsn, string $dir) {
        file_put_contents("$dir/001_init.sql", 'CREATE TABLE items (id int);');
        $env = 'DB_DSN=' . escapeshellarg("$dsn;options='--search_path=fondo'") . ' DB_USER=postgres DB_PASS=';
        $out = (string) shell_exec("$env php point db:sql " . escapeshellarg("$dir/001_init.sql") . ' 2>&1');
        expect($out)->toContain('1 archivo(s) aplicado(s)');

        $pdo = new PDO($dsn, 'postgres', '');
        expect($pdo->query("SELECT to_regclass('fondo._sql_migrations')::text")->fetchColumn())->toBe('fondo._sql_migrations');
        expect($pdo->query("SELECT to_regclass('fondo.items')::text")->fetchColumn())->toBe('fondo.items');
    });
});
