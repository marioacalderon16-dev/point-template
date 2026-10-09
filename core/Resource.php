<?php
declare(strict_types=1);
namespace Core;

class Resource
{
    private string $base;
    private string $name;
    private ?string $file;
    private ?string $group = null;
    private array $through = [];
    private array $expects = [];
    private array $guards = [];
    private $transform = null;
    private $onError = null;

    public function __construct(string $base, ?string $file = null)
    {
        $this->base = '/' . trim($base, '/');
        $this->name = str_replace('/', '.', trim($base, '/'));
        $this->file = $file;
    }

    public function group(string $name): self
    {
        $this->group = $name;
        return $this;
    }

    public function through(...$middleware): self
    {
        $this->through = array_merge($this->through, $middleware);
        return $this;
    }

    public function expects(array $rules): self
    {
        $this->expects = $rules;
        return $this;
    }

    public function guard(callable $check): self
    {
        $this->guards[] = $check;
        return $this;
    }

    public function transform(callable $fn): self
    {
        $this->transform = $fn;
        return $this;
    }

    public function onError(callable $fn): self
    {
        $this->onError = $fn;
        return $this;
    }

    public function list(callable $handler): self
    {
        return $this->register(['GET'], $this->base, 'list', $handler, []);
    }

    public function show(callable $handler): self
    {
        return $this->register(['GET'], "{$this->base}/{id}", 'show', $handler, [
            'id' => 'required|source:route',
        ]);
    }

    public function create(callable $handler): self
    {
        return $this->register(['POST'], $this->base, 'create', $handler, $this->expects);
    }

    public function update(callable $handler): self
    {
        return $this->register(['PUT', 'PATCH'], "{$this->base}/{id}", 'update', $handler, array_merge(
            $this->expects,
            ['id' => 'required|source:route']
        ));
    }

    public function delete(callable $handler): self
    {
        return $this->register(['DELETE'], "{$this->base}/{id}", 'delete', $handler, [
            'id' => 'required|source:route',
        ]);
    }

    private function register(array $methods, string $path, string $action, callable $handler, array $expects): self
    {
        RouteRegistry::getInstance()->addRoute([
            'file' => $this->file,
            'methods' => $methods,
            'path' => $path,
            'group' => $this->group,
            'expects' => $expects,
            'through' => $this->through,
            'callback' => $handler,
            'connectsTo' => [],
            'name' => "{$this->name}.{$action}",
            'transform' => $this->transform,
            'guards' => $this->guards,
            'onError' => $this->onError,
        ]);
        return $this;
    }
}
