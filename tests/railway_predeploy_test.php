<?php

test('parche 22: railway.json migra antes de desplegar con la ruta del WORKDIR del Dockerfile', function () {
    $root = dirname(__DIR__);
    $railway = json_decode((string) file_get_contents("$root/railway.json"), true);
    // El último WORKDIR es el de la imagen final (el primero es el de la etapa de composer)
    preg_match_all('/^WORKDIR\s+(\S+)/m', (string) file_get_contents("$root/Dockerfile"), $m);
    $workdir = end($m[1]);
    expect($railway['deploy']['preDeployCommand'] ?? null)->toBe(["php $workdir/scribe migrate"]);
});

test('parche 22: scribe migrate con ruta absoluta funciona desde otro directorio de trabajo', function () {
    $root = dirname(__DIR__);
    $dir = sys_get_temp_dir() . '/point_predeploy_' . bin2hex(random_bytes(4));
    mkdir("$dir/app/database/migrations", 0777, true);
    mkdir("$dir/elsewhere");
    copy("$root/scribe", "$dir/app/scribe");
    symlink("$root/core", "$dir/app/core");
    symlink("$root/vendor", "$dir/app/vendor");
    file_put_contents(
        "$dir/app/database/migrations/2026_01_01_000000_create_probes.table.php",
        "<?php\nuse Core\\Scribe\\Table;\n\nreturn Table::create('probes')->id()->str('name');\n"
    );
    try {
        $out = (string) shell_exec('cd ' . escapeshellarg("$dir/elsewhere")
            . ' && env ' . escapeshellarg("DB_DSN=sqlite:$dir/db.sqlite")
            . ' php ' . escapeshellarg("$dir/app/scribe") . ' migrate 2>&1');
        expect($out)->toContain('1 migraciones aplicadas');
    } finally {
        shell_exec('rm -rf ' . escapeshellarg($dir));
    }
});
