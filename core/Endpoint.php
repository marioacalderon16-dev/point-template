<?php
declare(strict_types=1);
namespace Core;

use Core\Middleware\MiddlewareManager;

class Endpoint
{
    private const HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    private string $file = '';
    private array $methods = [];
    private ?string $path = null;
    private ?string $group = null;
    private array $expects = [];
    private array $through = [];
    private mixed $callback = null;
    private array $connectsTo = [];
    private ?string $name = null;
    private mixed $transform = null;
    private array $guards = [];
    private mixed $onError = null;
    private array $uses = [];
    private ?int $cache = null;
    private ?string $mcp = null;
    private ?int $idempotent = null;
    private ?array $async = null;

    /** Bloqueos de idempotencia de esta petición: flock se libera solo al terminar el proceso. */
    private static array $locks = [];

    private function __construct() {}

    public static function from(string $file): self
    {
        $instance = new self();
        $instance->file = $file;
        return $instance;
    }

    public function at(string $route): self
    {
        // 'GET /ruta' o 'PUT|PATCH /ruta'; tolera espacios de más y falla claro con cualquier otra cosa
        $parts = preg_split('/\s+/', trim($route), 2);
        if (count($parts) !== 2 || !str_starts_with($parts[1], '/')) {
            throw new \InvalidArgumentException("Endpoint::at('{$route}'): formato inválido, se espera 'MÉTODO /ruta' (p. ej. 'GET /users').");
        }
        $methods = array_map('strtoupper', explode('|', $parts[0]));
        $invalid = array_diff($methods, self::HTTP_METHODS);
        if ($invalid !== []) {
            throw new \InvalidArgumentException("Endpoint::at('{$route}'): método HTTP no válido: " . implode(', ', $invalid) . '.');
        }
        $this->methods = $methods;
        $this->path = $parts[1];
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

    /** Se acumula como through()/guard(): varias llamadas suman reglas (la última gana por campo). */
    public function expects(array $rules): self
    {
        $this->expects = array_merge($this->expects, $rules);
        return $this;
    }

    /**
     * Guarda la respuesta de las peticiones GET durante $seconds segundos. La clave incluye la ruta,
     * los datos validados (query y parámetros) y el usuario autenticado: nunca mezcla usuarios.
     * Solo se guardan respuestas 2xx; las de error se recalculan siempre.
     */
    public function cache(int $seconds): self
    {
        if ($seconds < 1) {
            throw new \InvalidArgumentException('Endpoint::cache(): los segundos deben ser 1 o más.');
        }
        $this->cache = $seconds;
        return $this;
    }

    /**
     * Con la cabecera Idempotency-Key, una petición repetida (reintento, doble clic) no se vuelve a
     * ejecutar: recibe la misma respuesta (Idempotent-Replayed: true). La clave se guarda por ruta y
     * usuario durante $seconds. Misma clave con otros datos → 422; misma clave en curso → 409.
     */
    public function idempotent(int $seconds = 86400): self
    {
        if ($seconds < 1) {
            throw new \InvalidArgumentException('Endpoint::idempotent(): los segundos deben ser 1 o más.');
        }
        $this->idempotent = $seconds;
        return $this;
    }

    /**
     * Con la cabecera `Prefer: respond-async` la ruta responde 202 con un ticket y se ejecuta en la
     * cola; el resultado se consulta en GET /jobs/{id} durante $keep segundos. Sin la cabecera, igual que siempre.
     */
    public function asyncable(int $keep = 86400): self
    {
        $this->async = ['keep' => max(60, $keep), 'forced' => false];
        return $this;
    }

    /** Como asyncable(), pero siempre en la cola (procesos que nunca conviene esperar). */
    public function async(int $keep = 86400): self
    {
        $this->async = ['keep' => max(60, $keep), 'forced' => true];
        return $this;
    }

    /**
     * Expone la ruta como herramienta para asistentes de IA (`php point mcp`). La descripción le
     * explica a la IA cuándo usarla; los campos y sus reglas salen de expects().
     */
    public function mcp(string $description): self
    {
        $this->mcp = $description;
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
            'cache' => $this->cache,
            'mcp' => $this->mcp,
            'idempotent' => $this->idempotent,
            'async' => $this->async,
        ]);
        return $this;
    }

    public static function run()
    {
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $requestUri = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');

        $route = RouteRegistry::getInstance()->findByPathAndMethod($requestUri, $requestMethod);

        if (!$route) {
            // La ruta existe con otros métodos: 405 + Allow (RFC 9110), no 404
            $allowed = RouteRegistry::getInstance()->allowedMethods($requestUri);
            if ($allowed !== []) {
                header('Allow: ' . implode(', ', $allowed));
                self::sendJson(['status' => 405, 'message' => 'Método no permitido'], 405);
                return;
            }
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
            $inputRaw = array_intersect_key($inputRaw, $route['expects']);
            $inputSources = array_intersect_key($inputSources, $route['expects']);
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
            // Idempotencia: una repetición con la misma clave recibe la respuesta guardada
            $idem = self::idempotencyBegin($route, $validatedData);
            if ($idem !== null && isset($idem['replay'])) {
                header('Idempotent-Replayed: true');
                self::sendResponse($idem['replay']);
                return;
            }

            if (self::wantsAsync($route)) {
                $response = self::enqueueAsync($route, $requestMethod, $validatedData);
                self::idempotencyEnd($idem, $response);
                self::sendResponse($response);
                return;
            }

            $cacheKey = self::cacheKey($route, $requestMethod, $requestUri, $validatedData);
            if ($cacheKey !== null) {
                $cached = Cache::get($cacheKey);
                if ($cached !== null) {
                    header('X-Cache: HIT');
                    self::sendResponse($cached);
                    return;
                }
            }

            $response = self::execute($route, $validatedData, $context);

            if ($cacheKey !== null && self::isSuccessful($response)) {
                // Ida y vuelta por JSON: la caché no guarda objetos y la respuesta sale igual
                $response = is_string($response) ? $response : json_decode((string) json_encode($response), true);
                Cache::set($cacheKey, $response, $route['cache']);
                header('X-Cache: MISS');
            }

            self::idempotencyEnd($idem, $response);
            self::sendResponse($response);
        } catch (\Throwable $e) {
            if (!empty($route['onError'])) {
                $response = call_user_func($route['onError'], $e);
                if (!is_array($response)) {
                    // Un texto se envía como mensaje, no como cadena JSON suelta
                    $response = ['status' => 500, 'message' => is_scalar($response) ? (string) $response : 'Error'];
                }
                $status = $response['status'] ?? 500;
                self::sendJson($response, is_int($status) && $status >= 100 && $status <= 599 ? $status : 500);
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
     * Ejecuta el handler con datos ya validados (más uses, connectTo y transform). Lo usan run() y
     * el job asíncrono (AsyncEndpointJob), que no tiene petición HTTP.
     */
    public static function execute(array $route, array $data, array &$context): mixed
    {
        $handler = self::resolveHandler($route['callback']);
        if (!is_callable($handler)) {
            $desc = $route['callback'] instanceof \Closure ? 'Closure' : json_encode($route['callback']);
            throw new \RuntimeException("Handler no es invocable: {$desc} (" . ($route['file'] ?? '?') . ')');
        }

        $args = [$data];
        foreach ($route['uses'] ?? [] as $serviceClass) {
            $args[] = Container::resolve($serviceClass);
        }
        $response = call_user_func_array($handler, $args);

        if (!empty($route['connectsTo'])) {
            $response = (new Connector())->executeChain($route['connectsTo'], $response, $context);
        }
        if (!empty($route['transform'])) {
            $response = call_user_func($route['transform'], $response);
        }
        return $response;
    }

    private static function wantsAsync(array $route): bool
    {
        if (empty($route['async'])) {
            return false;
        }
        return $route['async']['forced']
            || stripos((string) InputExtractor::header('prefer', ''), 'respond-async') !== false;
    }

    /** Encola la ejecución y responde 202 con el ticket (con QUEUE_SYNC=true se ejecuta al momento). */
    private static function enqueueAsync(array $route, string $method, array $data): array
    {
        $id = bin2hex(random_bytes(16));
        $owner = $data['_user_id'] ?? null;
        $keep = $route['async']['keep'];
        Cache::set("job:$id", ['state' => 'queued', 'owner' => $owner, 'created_at' => date('c')], $keep);

        $payload = [
            'job_id' => $id,
            'file' => $route['file'],
            'method' => $method,
            'path' => $route['path'],
            'data' => $data,
            'keep' => $keep,
        ];
        if (filter_var($_ENV['QUEUE_SYNC'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            (new AsyncEndpointJob())->handle($payload);
        } else {
            Queue::dispatch(AsyncEndpointJob::class, $payload, 0, 1); // un solo intento: no repetir escrituras
        }

        header("Location: /jobs/$id");
        header('Preference-Applied: respond-async');
        $entry = Cache::get("job:$id") ?? [];
        return ['status' => 202, 'job' => $id, 'state' => $entry['state'] ?? 'queued', 'status_url' => "/jobs/$id"];
    }

    /**
     * @return array{replay: mixed}|array{key: string, hash: string, ttl: int}|null
     */
    private static function idempotencyBegin(array $route, array $data): ?array
    {
        $header = InputExtractor::header('idempotency-key');
        if (empty($route['idempotent']) || !is_string($header) || $header === '') {
            return null;
        }
        if (strlen($header) > 255) {
            self::sendJson(['status' => 400, 'message' => 'Idempotency-Key demasiado larga (máximo 255).'], 400);
        }

        $scope = implode('|', $route['methods']) . ' ' . $route['path'] . ' ' . json_encode($data['_user_id'] ?? null);
        $key = 'idem:' . sha1($scope . ' ' . $header);
        unset($data['_user']);
        ksort($data);
        $hash = sha1((string) json_encode($data));

        // Un proceso a la vez por clave; el bloqueo se libera solo al terminar (también con exit)
        $dir = (defined('BASE_PATH') ? BASE_PATH : getcwd()) . '/storage/locks';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $lock = fopen("$dir/" . sha1($key) . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            self::sendJson(['status' => 409, 'message' => 'Ya hay una petición en curso con esta Idempotency-Key.'], 409);
        }
        self::$locks[] = $lock;

        $stored = Cache::get($key);
        if (is_array($stored)) {
            if ($stored['hash'] !== $hash) {
                self::sendJson(['status' => 422, 'message' => 'Esta Idempotency-Key ya se usó con otros datos.'], 422);
            }
            return ['replay' => $stored['response']];
        }
        return ['key' => $key, 'hash' => $hash, 'ttl' => $route['idempotent']];
    }

    /** Guarda la respuesta para las repeticiones (no las 5xx: un error del servidor se puede reintentar). */
    private static function idempotencyEnd(?array $idem, mixed $response): void
    {
        if ($idem === null || !isset($idem['key'])) {
            return;
        }
        $status = is_array($response) ? ($response['status'] ?? 200) : 200;
        if (is_int($status) && $status >= 500) {
            return;
        }
        $response = is_string($response) ? $response : json_decode((string) json_encode($response), true);
        Cache::set($idem['key'], ['hash' => $idem['hash'], 'response' => $response], $idem['ttl']);
    }

    /** GET /jobs/{id}: estado de un trabajo asíncrono; solo lo ve quien lo lanzó (si había usuario). */
    public static function registerJobsRoute(): void
    {
        self::from('')->at('GET /jobs/{id}')->name('jobs.show')
            ->expects(['id' => 'required|string|regex:/^[a-f0-9]{32}$/'])
            ->handle(function (array $in) {
                $entry = Cache::get('job:' . $in['id']);
                if (!is_array($entry)) {
                    return Response::notFound('Trabajo no encontrado');
                }
                if (($entry['owner'] ?? null) !== null) {
                    $claims = preg_match('/^Bearer\s+(\S+)$/i', (string) InputExtractor::header('authorization', ''), $m)
                        ? Auth::verify($m[1]) : null;
                    if ((string) ($claims['sub'] ?? '') !== (string) $entry['owner']) {
                        return Response::notFound('Trabajo no encontrado');
                    }
                }
                unset($entry['owner']);
                return ['status' => 200, 'job' => $in['id']] + $entry;
            });
    }

    private static function cacheKey(array $route, string $method, string $uri, array $data): ?string
    {
        if (empty($route['cache']) || !in_array($method, ['GET', 'HEAD'], true)) {
            return null;
        }
        unset($data['_user']); // sus claims (iat, exp) cambian con cada token; basta _user_id
        ksort($data);
        return 'endpoint:' . sha1($method . ' ' . $uri . ' ' . json_encode($data));
    }

    private static function isSuccessful(mixed $response): bool
    {
        if (is_string($response)) {
            return true;
        }
        $status = is_array($response) ? ($response['status'] ?? 200) : 200;
        return is_int($status) && $status >= 200 && $status < 300;
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
        // Sangría solo en development: en producción son bytes de más en cada respuesta
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | (ErrorHandler::isDevelopment() ? JSON_PRETTY_PRINT : 0);
        echo json_encode($data, $flags);
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
