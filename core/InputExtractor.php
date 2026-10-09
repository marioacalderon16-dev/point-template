<?php
declare(strict_types=1);
namespace Core;

class InputExtractor
{
    public static function extract(string $requestMethod, string $requestUri, string $routePath): array
    {
        $fromRoute = self::extractFromRoute($requestUri, $routePath);
        $fromQuery = self::extractFromQuery();
        $fromBody  = self::extractFromBody($requestMethod);

        return array_merge($fromBody, $fromQuery, $fromRoute);
    }

    public static function extractWithSources(string $requestMethod, string $requestUri, string $routePath): array
    {
        $fromRoute = self::extractFromRoute($requestUri, $routePath);
        $fromQuery = self::extractFromQuery();
        $fromBody  = self::extractFromBody($requestMethod);

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

        $contentLength = $_SERVER['CONTENT_LENGTH'] ?? 0;
        if ($contentLength > 2 * 1024 * 1024) {
            throw new \RuntimeException('Payload too large', 413);
        }

        $input = file_get_contents('php://input');

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
