<?php

/** Carpeta temporal con storage/logs/<hoy>.log; devuelve [carpeta, archivo]. */
function logsDir(array $lines): array
{
    $dir = sys_get_temp_dir() . '/point_logs_' . bin2hex(random_bytes(4));
    mkdir("$dir/storage/logs", 0755, true);
    $file = "$dir/storage/logs/" . date('Y-m-d') . '.log';
    file_put_contents($file, implode("\n", $lines) . "\n");
    return [$dir, $file];
}

function logsRun(string $dir, string $args): array
{
    exec('cd ' . escapeshellarg($dir) . ' && php ' . escapeshellarg(dirname(__DIR__) . '/point') . " logs $args 2>&1", $out, $code);
    return [$out, $code];
}

test('logs: filtra por nivel mínimo, por texto y limita líneas', function () {
    [$dir] = logsDir([
        '[2026-10-09 10:00:00] DEBUG: detalle',
        '[2026-10-09 10:00:01] INFO: usuario creado {"id":1}',
        '[2026-10-09 10:00:02] WARNING: lento',
        '[2026-10-09 10:00:03] ERROR: fallo de pago {"order":7}',
    ]);
    try {
        [$all] = logsRun($dir, '--no-color');
        expect(count($all))->toBe(4);
        [$warn] = logsRun($dir, '--level=warning --no-color');
        expect($warn)->toBe(['[2026-10-09 10:00:02] WARNING: lento', '[2026-10-09 10:00:03] ERROR: fallo de pago {"order":7}']);
        [$grep] = logsRun($dir, '--grep=PAGO --no-color');
        expect($grep)->toBe(['[2026-10-09 10:00:03] ERROR: fallo de pago {"order":7}']);
        [$last] = logsRun($dir, '--lines=1 --no-color');
        expect($last)->toBe(['[2026-10-09 10:00:03] ERROR: fallo de pago {"order":7}']);
        [, $bad] = logsRun($dir, '--level=nada');
        expect($bad)->toBe(1);
    } finally {
        shell_exec('rm -rf ' . escapeshellarg($dir));
    }
});

test('logs -f: muestra en vivo las líneas nuevas', function () {
    [$dir, $file] = logsDir(['[2026-10-09 10:00:00] INFO: antes']);
    $proc = proc_open('exec php ' . escapeshellarg(dirname(__DIR__) . '/point') . ' logs -f --no-color', [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir);
    stream_set_blocking($pipes[1], false);
    try {
        usleep(700000);
        file_put_contents($file, "[2026-10-09 10:00:05] ERROR: nuevo en vivo\n", FILE_APPEND);
        $out = '';
        for ($i = 0; $i < 30 && !str_contains($out, 'nuevo en vivo'); $i++) {
            usleep(100000);
            $out .= (string) stream_get_contents($pipes[1]);
        }
        expect($out)->toContain('INFO: antes');
        expect($out)->toContain('ERROR: nuevo en vivo');
    } finally {
        proc_terminate($proc);
        proc_close($proc);
        shell_exec('rm -rf ' . escapeshellarg($dir));
    }
});
