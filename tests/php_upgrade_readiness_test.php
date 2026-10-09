<?php

/**
 * Tests de referencia del análisis de compatibilidad con la última versión de PHP
 * (2026-09-27). No prueban una migración ya hecha: documentan el comportamiento
 * actual de dos mecanismos señalados como riesgo, para detectar si cambia al
 * subir de versión de PHP.
 */

test('riesgo migración PHP: ErrorHandler no filtra por nivel — un warning/deprecation en el handler produce 500', function () {
    $res = http('GET', '/_test/deprecation-warning');
    expect($res->status)->toBe(500);
});

class ContainerProbeDependency
{
    public string $tag = 'dep-ok';
}

class ContainerProbeService
{
    public function __construct(public ContainerProbeDependency $dep)
    {
    }
}

class ContainerProbeUnresolvable
{
    public function __construct($sinTipoNiDefault)
    {
    }
}

test('Container::resolve() auto-wiring: resuelve dependencias tipadas anidadas', function () {
    Core\Container::clear();
    $service = Core\Container::resolve(ContainerProbeService::class);
    expect($service)->toBeInstanceOf(ContainerProbeService::class);
    expect($service->dep)->toBeInstanceOf(ContainerProbeDependency::class);
    expect($service->dep->tag)->toBe('dep-ok');
});

test('Container::resolve() lanza excepción clara si un parámetro no tiene tipo ni default', function () {
    expect(fn () => Core\Container::resolve(ContainerProbeUnresolvable::class))
        ->toThrow(\Exception::class);
});
