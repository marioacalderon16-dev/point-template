<?php
declare(strict_types=1);
namespace Core;

final class UploadedFile
{
    private string $tmpName;
    private string $originalName;
    private string $mimeType;
    private int $size;
    private int $error;

    public function __construct(array $file)
    {
        $this->tmpName     = $file['tmp_name'];
        $this->originalName = $file['name'];
        $this->mimeType    = $file['type'];
        $this->size        = $file['size'];
        $this->error       = $file['error'];
    }

    public static function fromGlobal(string $field): ?self
    {
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return new self($_FILES[$field]);
    }

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK && is_uploaded_file($this->tmpName);
    }

    public function store(string $directory, ?string $name = null): string
    {
        // Nunca fuera de storage/uploads: sin '..' en el directorio y el nombre sin rutas
        if (in_array('..', preg_split('#[/\\\\]#', $directory), true)) {
            throw new \InvalidArgumentException("Directorio de subida no permitido: {$directory}");
        }
        if ($name !== null) {
            $name = basename(str_replace('\\', '/', $name));
            if ($name === '' || $name === '.' || $name === '..') {
                throw new \InvalidArgumentException('Nombre de archivo no permitido.');
            }
        }

        $basePath = (defined('BASE_PATH') ? BASE_PATH : getcwd()) . '/storage/uploads';
        $dir = rtrim($basePath, '/') . '/' . trim($directory, '/');

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = $name ?? $this->generateName();
        $destination = $dir . '/' . $filename;

        if (!move_uploaded_file($this->tmpName, $destination)) {
            throw new \RuntimeException("No se pudo mover el archivo a {$destination}");
        }

        return trim($directory, '/') . '/' . $filename;
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->originalName, PATHINFO_EXTENSION));
    }

    public function originalName(): string
    {
        return $this->originalName;
    }

    public function mimeType(): string
    {
        if (function_exists('mime_content_type') && file_exists($this->tmpName)) {
            return mime_content_type($this->tmpName) ?: $this->mimeType;
        }
        return $this->mimeType;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function sizeKb(): float
    {
        return round($this->size / 1024, 2);
    }

    public function error(): int
    {
        return $this->error;
    }

    private function generateName(): string
    {
        return bin2hex(random_bytes(16)) . '.' . $this->extension();
    }
}
