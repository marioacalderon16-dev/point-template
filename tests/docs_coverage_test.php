<?php

/** La referencia debe documentar todo lo público: si se añade un método o un comando, este test lo exige. */
function docsRef(): string
{
    return (string) file_get_contents(dirname(__DIR__) . '/docs/REFERENCIA_ENDPOINT.md');
}

/** Ancla que genera GitHub para un título de Markdown. */
function docsSlug(string $heading): string
{
    $s = mb_strtolower(trim($heading));
    $s = (string) preg_replace('/[^\p{L}\p{N}\s-]/u', '', $s);
    return str_replace(' ', '-', $s);
}

function docsPublicMethods(string $class, array $exclude = []): array
{
    $methods = array_filter(
        (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC),
        fn ($m) => $m->getDeclaringClass()->getName() === $class && !str_starts_with($m->getName(), '__')
    );
    return array_values(array_diff(array_map(fn ($m) => $m->getName(), $methods), $exclude));
}

test('docs: cada método público de Endpoint y Resource está en la referencia', function () {
    $internal = ['run', 'resolveHandler', 'registerMiddleware', 'addGlobalMiddleware', 'registerJobsRoute', 'execute'];
    $missing = [];
    foreach (docsPublicMethods(Core\Endpoint::class, $internal) as $m) {
        if (!str_contains(docsRef(), "$m(")) $missing[] = "Endpoint::$m";
    }
    foreach (docsPublicMethods(Core\Resource::class) as $m) {
        if (!str_contains(docsRef(), "->$m(") && !str_contains(docsRef(), "$m(")) $missing[] = "Resource::$m";
    }
    expect($missing)->toBe([]);
});

test('docs: cada atajo de Rules y cada método de Auth y Response está en la referencia', function () {
    $missing = [];
    foreach ([App\Services\Rules::class => 'Rules', Core\Auth::class => 'Auth', Core\Response::class => 'Response'] as $class => $short) {
        foreach (docsPublicMethods($class, ['assertConfigured']) as $m) {
            if (!str_contains(docsRef(), "$short::$m(")) $missing[] = "$short::$m";
        }
    }
    expect($missing)->toBe([]);
});

test('docs: cada comando de point está en la tabla de la referencia', function () {
    preg_match_all("/^    '([a-z:]+)' => /m", (string) file_get_contents(dirname(__DIR__) . '/point'), $m);
    expect(count($m[1]))->toBeGreaterThan(20);
    $missing = array_values(array_filter($m[1], fn ($cmd) => !preg_match('/`' . preg_quote($cmd, '/') . '[` ]/', docsRef())));
    expect($missing)->toBe([]);
});

test('docs: los enlaces a secciones de la referencia (README, guía e índice) existen', function () {
    preg_match_all('/^#{1,4} (.+)$/m', docsRef(), $h);
    $anchors = array_map('docsSlug', $h[1]);
    $root = dirname(__DIR__);
    $broken = [];
    foreach (['README.md', 'docs/GUIA_INICIO.md', 'docs/REFERENCIA_ENDPOINT.md'] as $file) {
        preg_match_all('/\((?:docs\/)?(?:REFERENCIA_ENDPOINT\.md)?#([^)\s]+)\)/u', (string) file_get_contents("$root/$file"), $l);
        foreach ($l[1] as $anchor) {
            // En la guía y el README solo cuentan los enlaces a la referencia
            if ($file !== 'docs/REFERENCIA_ENDPOINT.md' && !str_contains((string) file_get_contents("$root/$file"), "REFERENCIA_ENDPOINT.md#$anchor")) continue;
            if (!in_array($anchor, $anchors, true)) $broken[] = "$file → #$anchor";
        }
    }
    expect(count($anchors))->toBeGreaterThan(30);
    expect(array_values(array_unique($broken)))->toBe([]);
});
