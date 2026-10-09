<?php

/** Levanta un cluster Postgres efímero (initdb en un directorio temporal, solo socket unix) y
 *  pasa su DSN al callback. Devuelve false si no hay binarios de Postgres instalados. */
function withEphemeralPostgres(\Closure $fn): bool
{
    $initdb = trim((string) shell_exec('command -v initdb 2>/dev/null'));
    $pgCtl = trim((string) shell_exec('command -v pg_ctl 2>/dev/null'));
    if ($initdb === '' || $pgCtl === '' || !extension_loaded('pdo_pgsql')) return false;

    // Ruta corta: los sockets unix tienen un límite de ~104 caracteres
    $dir = '/tmp/pgpt' . bin2hex(random_bytes(3));
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);

    shell_exec('LC_ALL=C ' . escapeshellarg($initdb) . ' -D ' . escapeshellarg("$dir/data") . ' -U postgres --auth=trust --no-locale -E UTF8 >/dev/null 2>&1');
    $opts = "-p $port -k $dir -c listen_addresses=''";
    shell_exec('LC_ALL=C ' . escapeshellarg($pgCtl) . ' -D ' . escapeshellarg("$dir/data") . ' -o ' . escapeshellarg($opts) . ' -w start >/dev/null 2>&1');

    try {
        $fn("pgsql:host=$dir;port=$port;dbname=postgres", $dir);
    } finally {
        shell_exec(escapeshellarg($pgCtl) . ' -D ' . escapeshellarg("$dir/data") . ' -m immediate stop >/dev/null 2>&1');
        shell_exec('rm -rf ' . escapeshellarg($dir));
    }
    return true;
}

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
