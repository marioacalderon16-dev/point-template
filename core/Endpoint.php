<?php
declare(strict_types=1);
namespace Core;

use Core\Middleware\MiddlewareManager;

class Endpoint
{
    private $file;
    private $methods = [];
    private $path;
    private $group;
    private $expects = [];
    private $through = [];
    private $callback;
    private $connectsTo = [];
    private $name;
    private $transform;
    private $guards = [];
    private $onError;
    private $uses = [];

    private function __construct() {}

    public static function from(string $file): self
    {
        $instance = new self();
        $instance->file = $file;
        return $instance;
    }

    public function at(string $route): self
    {
        [$methodsStr, $path] = explode(' ', $route, 2);
        $this->methods = array_map('strtoupper', explode('|', $methodsStr));
        $this->path = $path;
        return $this;
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

    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function connectTo(string ...$targets): self
    {
        foreach ($targets as $target) {
            $this->connectsTo[] = ['target' => $target, 'when' => null];
        }
        return $this;
    }

    public function when(callable $condition): self
    {
        if (empty($this->connectsTo)) {
            throw new \RuntimeException('when() must follow connectTo()');
        }
        $this->connectsTo[array_key_last($this->connectsTo)]['when'] = $condition;
        return $this;
    }

    public function transform(callable $fn): self
    {
        $this->transform = $fn;
        return $this;
    }

    public function guard(callable $check): self
    {
        $this->guards[] = $check;
        return $this;
    }

    public function onError(callable $fn): self
    {
        $this->onError = $fn;
        return $this;
    }

    public function uses(string ...$services): self
    {
        $this->uses = array_merge($this->uses, $services);
        return $this;
    }

    public static function resource(string $base): Resource
    {
        $file = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['file'] ?? null;
        return new Resource($base, $file);
    }

    public function handle($callback): self
    {
        $this->callback = $callback;

        RouteRegistry::getInstance()->addRoute([
            'file' => $this->file,
            'methods' => $this->methods,
            'path' => $this->path,
            'group' => $this->group,
            'expects' => $this->expects,
            'through' => $this->through,
            'callback' => $this->callback,
            'connectsTo' => $this->connectsTo,
            'name' => $this->name,
            'transform' => $this->transform,
            'guards' => $this->guards,
            'onError' => $this->onError,
            'uses' => $this->uses,
        ]);
        return $this;
    }

    public static function run()
    {
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $requestUri = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');

        $route = RouteRegistry::getInstance()->findByPathAndMethod($requestUri, $requestMethod);

        if (!$route) {
            self::sendJson(['error' => 'Ruta no encontrada'], 404);
            return;
        }

        $extraction = InputExtractor::extractWithSources($requestMethod, $requestUri, $route['path']);
        $inputRaw = $extraction['values'];
        $inputSources = $extraction['sources'];

        $context = [
            'method' => $requestMethod,
            'path' => $requestUri,
            'route' => $route,
            'visited' => [],
        ];

        // Los middlewares globales ya corrieron en index.php (antes del 404 y del preflight)
        if ($route['group']) {
            // Un grupo no declarado en config/middleware.php no debe dejar pasar la petición
            // sin sus middlewares (p. ej. 'protected' sin 'auth')
            if (!MiddlewareManager::hasGroup($route['group'])) {
                throw new \RuntimeException("Grupo de middleware no definido: '{$route['group']}' (config/middleware.php)");
            }
            $groupMiddlewares = MiddlewareManager::getGroup($route['group']);
            if (!MiddlewareManager::execute($groupMiddlewares, $inputRaw, $context))
                return;
        }
        if (!MiddlewareManager::execute($route['through'], $inputRaw, $context))
            return;

        if (isset($context['inputSources'])) {
            $inputSources = array_merge($inputSources, $context['inputSources']);
        }

        if (!empty($route['guards'])) {
            foreach ($route['guards'] as $guard) {
                if (!call_user_func($guard, $inputRaw)) {
                    self::sendJson(['status' => 403, 'message' => 'Forbidden'], 403);
                    return;
                }
            }
        }

        // Datos inyectados por middleware (_user_id, _user...) sobreviven al filtro de expects
        $middlewareData = array_intersect_key(
            $inputRaw,
            array_filter($inputSources, fn ($s) => $s === 'middleware')
        );

        // APP_STRICT_MODE llega del entorno como texto ('true'); el Validator exige bool
        $strict = filter_var($_ENV['APP_STRICT_MODE'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Modo estricto: campos del cuerpo no declarados → 422. Se comprueba antes de que expects
        // los filtre (después ya no quedaría ninguno que rechazar)
        if ($strict && !empty($route['expects'])) {
            $extra = array_keys(array_diff_key(
                array_filter($inputSources, fn ($s) => $s === 'body'),
                $route['expects']
            ));
            if ($extra) {
                self::sendJson([
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => array_combine($extra, array_map(fn ($k) => ["Campo no permitido: {$k}"], $extra)),
                ], 422);
                return;
            }
        }

        if (!empty($route['expects'])) {
            $allowedKeys = array_keys($route['expects']);
            $inputRaw = array_intersect_key($inputRaw, array_flip($allowedKeys));
            $inputSources = array_intersect_key($inputSources, array_flip($allowedKeys));
        }

        if (!empty($route['expects'])) {
            $validator = new Validator($inputRaw, $route['expects'], $inputSources, $strict);
            if (!$validator->passes()) {
                self::sendJson([
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
                return;
            }
            $validatedData = array_merge($validator->validated(), $middlewareData);
        } else {
            $validatedData = $inputRaw;
        }

        try {
            $handler = self::resolveHandler($route['callback']);

            if (!is_callable($handler)) {
                throw new \RuntimeException('Handler no es invocable: ' . json_encode($route['callback']));
            }

            $args = [$validatedData];
            foreach ($route['uses'] ?? [] as $serviceClass) {
                $args[] = Container::resolve($serviceClass);
            }
            $response = call_user_func_array($handler, $args);

            if (!empty($route['connectsTo'])) {
                $connector = new Connector();
                $response = $connector->executeChain($route['connectsTo'], $response, $context);
            }

            if (!empty($route['transform'])) {
                $response = call_user_func($route['transform'], $response);
            }

            self::sendResponse($response);
        } catch (\Throwable $e) {
            if (!empty($route['onError'])) {
                $response = call_user_func($route['onError'], $e);
                self::sendJson($response, is_array($response) ? ($response['status'] ?? 500) : 500);
                return;
            }
            self::handleExecutionError($e);
        }
    }

    public static function resolveHandler($handler)
    {
        if (is_string($handler) && class_exists($handler)) {
            return Container::resolve($handler);
        }

        if (is_array($handler) && isset($handler[0]) && is_string($handler[0]) && class_exists($handler[0])) {
            $instance = Container::resolve($handler[0]);
            return [$instance, $handler[1]];
        }

        return $handler;
    }

    public static function registerMiddleware(string $name, callable $handler): void
    {
        MiddlewareManager::register($name, $handler);
    }

    public static function addGlobalMiddleware(string $name): void
    {
        MiddlewareManager::global($name);
    }

    /**
     * Respuesta final del handler: string → texto plano; array con 'status'
     * entero HTTP válido → ese código; 204 sin cuerpo; resto → JSON 200.
     */
    private static function sendResponse($data): void
    {
        if (is_string($data)) {
            http_response_code(200);
            header('Content-Type: text/plain; charset=utf-8');
            echo $data;
            exit;
        }

        $code = is_array($data) && is_int($data['status'] ?? null)
            && $data['status'] >= 100 && $data['status'] <= 599
            ? $data['status'] : 200;

        if ($code === 204) {
            http_response_code(204);
            exit;
        }

        self::sendJson($data, $code);
    }

    private static function sendJson($data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    private static function handleExecutionError(\Throwable $e): void
    {
        if (class_exists('Core\ErrorHandler')) {
            ErrorHandler::handleException($e);
        } else {
            self::sendJson([
                'error' => 'Error interno del servidor',
                'message' => ($_ENV['APP_ENV'] ?? '') === 'development' ? $e->getMessage() : 'Ocurrió un error'
            ], 500);
        }
    }
}
