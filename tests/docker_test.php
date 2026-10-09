<?php

test('parche 23: la imagen Docker trae los drivers de Postgres y de MySQL', function () {
    $dockerfile = (string) file_get_contents(dirname(__DIR__) . '/Dockerfile');
    preg_match('/docker-php-ext-install\s+([^\\\\\n]+)/', $dockerfile, $m);
    $extensions = preg_split('/\s+/', trim($m[1] ?? ''));
    expect($extensions)->toContain('pdo_pgsql');
    expect($extensions)->toContain('pdo_mysql');
});
