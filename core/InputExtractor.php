<?php
declare(strict_types=1);
namespace Core;

class InputExtractor
{
    /** Cuerpo inyectado por `php point call`: en CLI php://input siempre está vacío. */
    public static ?array $bodyOverride = null;

    public static function extract(string $requestMethod, string $requestUri, string $routePath): array
    {
        $fromRoute = self::extractFromRoute($requestUri, $routePath);
        $fromQuery = self::withoutReserved(self::extractFromQuery());
        $fromBody  = self::withoutReserved(self::extractFromBody($requestMethod));

        return array_merge($fromBody, $fromQuery, $fromRoute);
    }

    public static function extractWithSources(string $requestMethod, string $requestUri, string $routePath): array
    {
        $fromRoute = self::extractFromRoute($requestUri, $routePath);
        $fromQuery = self::withoutReserved(self::extractFromQuery());
        $fromBody  = self::withoutReserved(self::extractFromBody($requestMethod));

        $values = array_merge($fromBody, $fromQuery, $fromRoute);
        $sources = [];

        foreach (array_keys($fromBody) as $field) $sources[$field] = 'body';
        foreach (array_keys($fromQuery) as $field) $sources[$field] = 'query';
        foreach (array_keys($fromRoute) as $field) $sources[$field] = 'route';

        return ['values' => $values, 'sources' => $sources];
    }

    public static function headers(): array
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                $headers[strtolower($name)] = $value;
            }
        } else {
            $prefix = 'HTTP_';
            foreach ($_SERVER as $key => $value) {
                if (strpos($key, $prefix) === 0) {
                    $headerName = strtolower(str_replace('_', '-', substr($key, strlen($prefix))));
                    $headers[$headerName] = $value;
                }
            }
        }

        $special = [
            'CONTENT_TYPE' => 'content-type',
            'CONTENT_LENGTH' => 'content-length',
            'AUTHORIZATION' => 'authorization',
        ];
        foreach ($special as $serverKey => $headerName) {
            if (isset($_SERVER[$serverKey])) {
                $headers[$headerName] = $_SERVER[$serverKey];
            }
        }

        return $headers;
    }

    public static function header(string $name, mixed $default = null): mixed
    {
        return self::headers()[strtolower($name)] ?? $default;
    }

    private static function extractFromRoute(string $requestUri, string $routePath): array
    {
        $params = [];
        $routePath = trim($routePath, '/');
        $requestUri = trim($requestUri, '/');

        if (!preg_match_all('/\{([a-zA-Z0-9_]+)(:[a-zA-Z]+)?\}/', $routePath, $matches, PREG_SET_ORDER)) {
            return $params;
        }

        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)(:[a-zA-Z]+)?\}/', '([^/]+)', $routePath);
        $pattern = '#^' . $pattern . '$#';

        if (preg_match($pattern, $requestUri, $captures)) {
            foreach ($matches as $index => $match) {
                $paramName = $match[1];
                $value = $captures[$index + 1] ?? null;
                if ($value !== null) {
                    $params[$paramName] = $value;
                }
            }
        }

        return $params;
    }

    /**
     * Las claves que empiezan por '_' (_user_id, _user...) las reservan los middlewares: si las
     * aceptáramos del cliente, podría suplantar al usuario autenticado o saltarse el rate limit.
     */
    private static function withoutReserved(array $data): array
    {
        return array_filter($data, fn ($key) => !is_string($key) || !str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
    }

    private static function extractFromQuery(): array
    {
        return $_GET;
    }

    private static function extractFromBody(string $requestMethod): array
    {
        $allowedMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];
        if (!in_array(strtoupper($requestMethod), $allowedMethods)) {
            return [];
        }

        if (self::$bodyOverride !== null) {
            return self::$bodyOverride;
        }

        $maxBytes = 2 * 1024 * 1024;
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBytes) {
            throw new \RuntimeException('Payload too large', 413);
        }

        // Lee como máximo un byte más del límite: con Transfer-Encoding chunked no hay Content-Length
        $input = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if (is_string($input) && strlen($input) > $maxBytes) {
            throw new \RuntimeException('Payload too large', 413);
        }

        $json = json_decode($input, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return is_array($json) ? $json : [];
        }

        if (is_string($input) && strlen($input) > 0) {
            parse_str($input, $formData);
            return is_array($formData) ? $formData : [];
        }

        if (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false) {
            $data = $_POST;
            foreach ($_FILES as $field => $file) {
                if ($file['error'] !== UPLOAD_ERR_NO_FILE) {
                    $data[$field] = new UploadedFile($file);
                }
            }
            return $data;
        }

        return [];
    }
}
