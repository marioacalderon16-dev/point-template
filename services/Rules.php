<?php
declare(strict_types=1);
namespace App\Services;

/**
 * Reglas de validación reutilizables para ->expects([...]).
 * Cada método devuelve un array de reglas de Core\Validator.
 */
final class Rules
{
    /** Entero >= 1 (IDs autoincrementales). */
    public static function id(bool $required = true): array
    {
        return [self::presence($required), 'integer', 'min:1'];
    }

    public static function uuid(bool $required = true): array
    {
        return [self::presence($required), 'uuid'];
    }

    /**
     * Valor dentro de una lista o de los casos de un enum respaldado.
     * @param array<int, string|int>|class-string<\BackedEnum> $allowed
     */
    public static function status(array|string $allowed, bool $required = true): array
    {
        if (is_string($allowed)) {
            if (!is_subclass_of($allowed, \BackedEnum::class)) {
                throw new \InvalidArgumentException("Rules::status: {$allowed} no es un enum respaldado.");
            }
            $allowed = array_map(fn (\BackedEnum $case) => $case->value, $allowed::cases());
        }
        if ($allowed === []) {
            throw new \InvalidArgumentException('Rules::status: la lista de valores está vacía.');
        }
        foreach ($allowed as $value) {
            if (str_contains((string) $value, ',')) {
                throw new \InvalidArgumentException("Rules::status: el valor '{$value}' no puede contener comas.");
            }
        }
        return [self::presence($required), 'in:' . implode(',', $allowed)];
    }

    public static function email(bool $required = true, int $max = 255): array
    {
        return [self::presence($required), 'trim', 'lowercase', 'email', "max:{$max}"];
    }

    public static function text(int $max = 255, bool $required = true, int $min = 0): array
    {
        $rules = [self::presence($required), 'string', 'trim'];
        if ($min > 0) {
            $rules[] = "min:{$min}";
        }
        $rules[] = "max:{$max}";
        return $rules;
    }

    public static function boolean(bool $required = true): array
    {
        return [self::presence($required), 'boolean'];
    }

    public static function date(bool $required = true, string $format = 'Y-m-d'): array
    {
        return [self::presence($required), "date:{$format}"];
    }

    /** Campos page y limit opcionales con valores por defecto. Uso: ...Rules::pagination() */
    public static function pagination(int $maxLimit = 100, int $defaultLimit = 20): array
    {
        return [
            'page'  => ['optional', 'integer', 'min:1', 'default:1'],
            'limit' => ['optional', 'integer', 'min:1', "max:{$maxLimit}", "default:{$defaultLimit}"],
        ];
    }

    private static function presence(bool $required): string
    {
        return $required ? 'required' : 'optional';
    }
}
