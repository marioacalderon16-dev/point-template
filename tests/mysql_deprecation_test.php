<?php

test('parche 24: conectar a MySQL no emite deprecaciones (el ErrorHandler las convierte en 500)', function () {
    if (!extension_loaded('pdo_mysql')) {
        expect(true)->toBeTrue(); // sin pdo_mysql no hay nada que comprobar
        return;
    }
    $root = dirname(__DIR__);
    // Puerto 1: conexión rechazada al instante; las opciones (y sus avisos) se evalúan antes
    $code = 'require ' . var_export("$root/vendor/autoload.php", true) . '; '
        . 'try { Core\QueryBuilder::connection("mysql:host=127.0.0.1;port=1;dbname=x"); } catch (PDOException $e) {}';
    $out = (string) shell_exec('php -d error_reporting=-1 -d display_errors=stderr -r ' . escapeshellarg($code) . ' 2>&1');
    expect($out)->not()->toContain('Deprecated');
});
