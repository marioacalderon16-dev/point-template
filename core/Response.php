<?php
declare(strict_types=1);
namespace Core;

final class Response
{
    public static function ok(mixed $data = null, string $message = 'OK'): array
    {
        $response = ['status' => 200, 'message' => $message];
        if ($data !== null) {
            $response['data'] = $data;
        }
        return $response;
    }

    public static function created(mixed $data = null, string $message = 'Recurso creado'): array
    {
        $response = ['status' => 201, 'message' => $message];
        if ($data !== null) {
            $response['data'] = $data;
        }
        return $response;
    }

    public static function noContent(): array
    {
        return ['status' => 204];
    }

    public static function error(string $message, int $status = 400, array $errors = []): array
    {
        $response = ['status' => $status, 'message' => $message];
        if (!empty($errors)) {
            $response['errors'] = $errors;
        }
        return $response;
    }

    public static function notFound(string $message = 'Recurso no encontrado'): array
    {
        return ['status' => 404, 'message' => $message];
    }

    public static function forbidden(string $message = 'Acceso denegado'): array
    {
        return ['status' => 403, 'message' => $message];
    }

    public static function paginated(array $paginatedResult): array
    {
        return [
            'status' => 200,
            'data'   => $paginatedResult['data'] ?? [],
            'meta'   => $paginatedResult['meta'] ?? [],
        ];
    }
}
