<?php

test('parche 23: la imagen Docker trae los drivers de Postgres y de MySQL', function () {
    $dockerfile = (string) file_get_contents(dirname(__DIR__) . '/Dockerfile');
    preg_match('/docker-php-ext-install\s+([^\\\\\n]+)/', $dockerfile, $m);
    $extensions = preg_split('/\s+/', trim($m[1] ?? ''));
    expect($extensions)->toContain('pdo_pgsql');
    expect($extensions)->toContain('pdo_mysql');
});

test('entrypoint: sintaxis válida, worker opcional en el mismo contenedor y storage preparado para un volumen', function () {
    $file = dirname(__DIR__) . '/docker/entrypoint.sh';
    exec('sh -n ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    expect($code)->toBe(0);
    $sh = (string) file_get_contents($file);
    expect($sh)->toContain('if [ "${WORKER_ENABLED:-false}" = "true" ]; then');
    expect($sh)->toContain('php /var/www/html/point work --max-time=3600');
    expect($sh)->toContain('chown -R www-data:www-data /var/www/html/storage');
    // El worker y el scheduler arrancan antes que Apache (exec reemplaza el proceso)
    expect(strpos($sh, 'WORKER_ENABLED'))->toBeLessThan(strpos($sh, 'exec apache2-foreground'));
});
