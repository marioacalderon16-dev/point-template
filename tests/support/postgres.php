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

/**
 * Base Postgres nueva y vacía para un test: un cluster efímero si hay binarios de Postgres (local) o,
 * si no, una base temporal en el servidor de DB_DSN del proceso (CI). Devuelve false si no hay ninguna.
 */
function withFreshPostgres(\Closure $fn): bool
{
    if (withEphemeralPostgres(fn (string $dsn) => $fn($dsn, 'postgres', ''))) {
        return true;
    }
    $dsn = (string) getenv('DB_DSN');
    if (!str_starts_with($dsn, 'pgsql:') || !extension_loaded('pdo_pgsql')) {
        return false;
    }
    $user = (string) getenv('DB_USER');
    $pass = (string) getenv('DB_PASS');
    $name = 'point_tmp_' . bin2hex(random_bytes(4));
    $admin = new PDO($dsn, $user, $pass);
    $admin->exec("CREATE DATABASE $name");
    try {
        $fn((string) preg_replace('/dbname=[^;]+/', "dbname=$name", $dsn), $user, $pass);
    } finally {
        $admin->exec("DROP DATABASE IF EXISTS $name WITH (FORCE)");
    }
    return true;
}
