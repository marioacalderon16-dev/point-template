<?php

test('parche 14: core/Testing.php compila sin avisos de deprecación', function () {
    $root = dirname(__DIR__);
    $code = "require 'vendor/autoload.php'; require 'core/Testing.php'; echo 'LOADED';";
    $out = (string) shell_exec('cd ' . escapeshellarg($root) . ' && php -d error_reporting=-1 -d display_errors=1 -r ' . escapeshellarg($code) . ' 2>&1');
    expect($out)->toContain('LOADED');
    expect($out)->not()->toContain('Deprecated');
});
