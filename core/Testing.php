<?php
declare(strict_types=1);

namespace Core {

    class TestRunner
    {
        private static array $tests = [];
        private static string $currentFile = '';
        private static int $passed = 0;
        private static int $failed = 0;
        private static float $startTime = 0;
        private static ?int $serverPid = null;
        private static int $serverPort = 0;
        private static mixed $serverProcess = null;

        public static function add(string $name, \Closure $fn): void
        {
            self::$tests[] = [
                'name' => $name,
                'fn' => $fn,
                'file' => self::$currentFile,
            ];
        }

        public static function setFile(string $file): void
        {
            self::$currentFile = $file;
        }

        public static function discover(string $dir): array
        {
            $files = [];

            if (!is_dir($dir)) {
                return $files;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) continue;
                if (!preg_match('/(_test|Test)\.php$/', $file->getFilename())) continue;
                $files[] = $file->getPathname();
            }

            sort($files);
            return $files;
        }

        public static function loadAndRun(array $files, ?string $filter = null): int
        {
            self::$tests = [];
            self::$passed = 0;
            self::$failed = 0;
            self::$startTime = microtime(true);

            foreach ($files as $file) {
                self::$currentFile = $file;
                require_once $file;
            }

            if (empty(self::$tests)) {
                echo "  No se encontraron tests.\n";
                return 0;
            }

            $currentFile = '';

            foreach (self::$tests as $test) {
                if ($filter && stripos($test['name'], $filter) === false) {
                    continue;
                }

                if ($test['file'] !== $currentFile) {
                    $currentFile = $test['file'];
                    $relative = self::relativePath($currentFile);
                    echo "\n  \033[1m$relative\033[0m\n";
                }

                try {
                    call_user_func($test['fn']);
                    self::$passed++;
                    echo "  \033[32m  OK\033[0m  {$test['name']}\n";
                } catch (\Throwable $e) {
                    self::$failed++;
                    echo "  \033[31mFAIL\033[0m  {$test['name']}\n";
                    echo "        \033[31m{$e->getMessage()}\033[0m\n";
                }
            }

            self::stopServer();

            $elapsed = round((microtime(true) - self::$startTime) * 1000);
            $total = self::$passed + self::$failed;

            echo "\n  ──────────────────────────────\n";

            if (self::$failed === 0) {
                echo "  \033[32m$total tests, all passed\033[0m ({$elapsed}ms)\n";
            } else {
                echo "  \033[31m" . self::$failed . " failed\033[0m, ";
                echo "\033[32m" . self::$passed . " passed\033[0m";
                echo " ($total total, {$elapsed}ms)\n";
            }

            return self::$failed > 0 ? 1 : 0;
        }

        public static function ensureServer(): void
        {
            if (self::$serverPid !== null) return;

            self::$serverPort = self::findFreePort();

            $descriptors = [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ];

            $cmd = sprintf('exec php -S 127.0.0.1:%d index.php', self::$serverPort);
            self::$serverProcess = proc_open($cmd, $descriptors, $pipes, getcwd());

            if (!is_resource(self::$serverProcess)) {
                throw new \RuntimeException('No se pudo iniciar el servidor de test');
            }

            $status = proc_get_status(self::$serverProcess);
            self::$serverPid = $status['pid'];

            $maxWait = 50;
            while ($maxWait-- > 0) {
                usleep(100000);
                $fp = @fsockopen('127.0.0.1', self::$serverPort, $errno, $errstr, 0.1);
                if ($fp) {
                    fclose($fp);
                    return;
                }
            }

            self::stopServer();
            throw new \RuntimeException("Servidor de test no respondio en puerto " . self::$serverPort);
        }

        public static function stopServer(): void
        {
            if (self::$serverProcess !== null && is_resource(self::$serverProcess)) {
                proc_terminate(self::$serverProcess);
                proc_close(self::$serverProcess);
                self::$serverProcess = null;
                self::$serverPid = null;
            } elseif (self::$serverPid !== null) {
                @posix_kill(self::$serverPid, SIGTERM);
                self::$serverPid = null;
            }
        }

        public static function getServerPort(): int
        {
            return self::$serverPort;
        }

        private static function findFreePort(): int
        {
            $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            if (!$sock) {
                return random_int(9100, 9900);
            }
            $name = stream_socket_get_name($sock, false);
            fclose($sock);
            return (int) explode(':', $name)[1];
        }

        private static function relativePath(string $path): string
        {
            $cwd = getcwd() . '/';
            if (str_starts_with($path, $cwd)) {
                return substr($path, strlen($cwd));
            }
            return $path;
        }
    }

    class TestResponse
    {
        public function __construct(
            public readonly int $status,
            public readonly mixed $body,
            public readonly array $headers,
        ) {}
    }

    class Expectation
    {
        private mixed $value;
        private bool $negated = false;

        public function __construct(mixed $value)
        {
            $this->value = $value;
        }

        public function not(): self
        {
            $this->negated = !$this->negated;
            return $this;
        }

        public function toBe(mixed $expected): self
        {
            return $this->assert(
                $this->value === $expected,
                'Expected ' . self::format($expected) . ', got ' . self::format($this->value)
            );
        }

        public function toEqual(mixed $expected): self
        {
            return $this->assert(
                $this->value == $expected,
                'Expected ' . self::format($expected) . ' (loose), got ' . self::format($this->value)
            );
        }

        public function toBeTrue(): self
        {
            return $this->assert($this->value === true, 'Expected true, got ' . self::format($this->value));
        }

        public function toBeFalse(): self
        {
            return $this->assert($this->value === false, 'Expected false, got ' . self::format($this->value));
        }

        public function toBeNull(): self
        {
            return $this->assert($this->value === null, 'Expected null, got ' . self::format($this->value));
        }

        public function toBeEmpty(): self
        {
            return $this->assert(empty($this->value), 'Expected empty, got ' . self::format($this->value));
        }

        public function toContain(mixed $needle): self
        {
            if (is_array($this->value)) {
                return $this->assert(
                    in_array($needle, $this->value, true),
                    self::format($needle) . ' not found in array'
                );
            }
            return $this->assert(
                str_contains((string) $this->value, (string) $needle),
                '"' . $needle . '" not found in "' . $this->value . '"'
            );
        }

        public function toHaveKey(string $key): self
        {
            return $this->assert(
                is_array($this->value) && array_key_exists($key, $this->value),
                "Key '$key' not found"
            );
        }

        public function toHaveCount(int $count): self
        {
            $actual = is_countable($this->value) ? count($this->value) : 0;
            return $this->assert($actual === $count, "Expected count $count, got $actual");
        }

        public function toBeGreaterThan(int|float $n): self
        {
            return $this->assert($this->value > $n, self::format($this->value) . " is not > $n");
        }

        public function toBeLessThan(int|float $n): self
        {
            return $this->assert($this->value < $n, self::format($this->value) . " is not < $n");
        }

        public function toMatch(string $pattern): self
        {
            return $this->assert(
                preg_match($pattern, (string) $this->value) === 1,
                '"' . $this->value . '" does not match ' . $pattern
            );
        }

        public function toBeInstanceOf(string $class): self
        {
            return $this->assert(
                $this->value instanceof $class,
                'Expected instance of ' . $class . ', got ' . get_debug_type($this->value)
            );
        }

        public function toThrow(?string $class = null): self
        {
            if (!($this->value instanceof \Closure)) {
                throw new \RuntimeException('toThrow() requires a closure');
            }

            $thrown = null;
            try {
                ($this->value)();
            } catch (\Throwable $e) {
                $thrown = $e;
            }

            if (!$this->negated) {
                if (!$thrown) {
                    throw new \RuntimeException('Expected exception, none thrown');
                }
                if ($class && !($thrown instanceof $class)) {
                    throw new \RuntimeException("Expected $class, got " . get_class($thrown));
                }
            } else {
                if ($thrown) {
                    throw new \RuntimeException('Expected no exception, got ' . get_class($thrown) . ': ' . $thrown->getMessage());
                }
            }

            return $this;
        }

        private function assert(bool $condition, string $message): self
        {
            $result = $this->negated ? !$condition : $condition;
            if (!$result) {
                $prefix = $this->negated ? 'NOT: ' : '';
                throw new \RuntimeException($prefix . $message);
            }
            return $this;
        }

        private static function format(mixed $value): string
        {
            if ($value === null) return 'null';
            if ($value === true) return 'true';
            if ($value === false) return 'false';
            if (is_string($value)) return "\"$value\"";
            if (is_array($value)) return 'array(' . count($value) . ')';
            if (is_object($value)) return get_class($value);
            return (string) $value;
        }
    }
}

namespace {

    function test(string $name, \Closure $fn): void
    {
        Core\TestRunner::add($name, $fn);
    }

    function expect(mixed $value): Core\Expectation
    {
        return new Core\Expectation($value);
    }

    function http(string $method, string $path, array $data = [], array $headers = []): Core\TestResponse
    {
        Core\TestRunner::ensureServer();

        $url = 'http://localhost:' . Core\TestRunner::getServerPort() . $path;

        $headerLines = ['Content-Type: application/json'];
        foreach ($headers as $key => $value) {
            $headerLines[] = "{$key}: {$value}";
        }

        $body = '';
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE']) && !empty($data)) {
            $body = json_encode($data);
        } elseif ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        $status = 500;
        $responseHeaders = [];

        // PHP >= 8.4 (composer.json); $http_response_header está deprecado desde 8.5
        $rawHeaders = http_get_last_response_headers() ?? [];

        foreach ($rawHeaders as $h) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $h, $m)) {
                $status = (int) $m[1];
            } else {
                $parts = explode(':', $h, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
            }
        }

        $decoded = json_decode($response ?: '', true);

        return new Core\TestResponse($status, $decoded ?? $response, $responseHeaders);
    }
}
