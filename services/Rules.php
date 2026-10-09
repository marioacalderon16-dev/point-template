<?php
declare(strict_types=1);
namespace App\Services;

use Core\DB;
use Core\Validator;

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

    /**
     * Máquina de estados: el campo solo admite los cambios permitidos desde el estado actual del
     * registro, que se lee de la base de datos. $steps = ['pending' => ['paid', 'cancelled'], ...].
     * Si el registro no existe, la regla no decide (el handler responde 404).
     * Varias peticiones a la vez: guardar con ->where($column, $estadoLeido) y responder 409 si
     * no actualiza ninguna fila.
     */
    public static function transition(
        string $table,
        array $steps,
        string $column = 'status',
        string $key = 'id',
        string $idField = 'id',
        bool $required = true,
    ): array {
        foreach ([$table, $column, $key, $idField] as $identifier) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
                throw new \InvalidArgumentException("Rules::transition: identificador no válido '{$identifier}'.");
            }
        }
        self::assertSteps($steps);

        $name = 'transition_' . substr(sha1(serialize([$table, $steps, $column, $key, $idField])), 0, 12);
        Validator::extend($name, function ($value, $param, array $input) use ($table, $steps, $column, $key, $idField) {
            $id = $input[$idField] ?? null;
            if (!is_scalar($id) || $id === '') {
                return true; // sin ID no hay registro que comparar (lo valida su propia regla)
            }
            $row = DB::table($table)->select($column)->where($key, $id)->first();
            if ($row === null) {
                return true;
            }
            $from = (string) $row[$column];
            $to = (string) $value;
            if (!array_key_exists($from, $steps)) {
                return "El estado actual '{$from}' no está en el flujo.";
            }
            if ($steps[$from] === []) {
                return "'{$from}' es un estado final: no admite cambios.";
            }
            if (!in_array($to, $steps[$from], true)) {
                return "No se puede pasar de '{$from}' a '{$to}'. Permitidos: " . implode(', ', $steps[$from]) . '.';
            }
            return true;
        });

        return [self::presence($required), 'string', 'in:' . implode(',', array_keys($steps)), $name];
    }

    /** ¿Se puede pasar de $from a $to? Para jobs y acciones, con el mismo mapa que Rules::transition. */
    public static function canTransition(array $steps, string $from, string $to): bool
    {
        return in_array($to, $steps[$from] ?? [], true);
    }

    /** Diagrama Mermaid del flujo (se ve como gráfico en GitHub o en el README). */
    public static function diagram(array $steps): string
    {
        self::assertSteps($steps);
        $lines = ['stateDiagram-v2'];
        foreach ($steps as $from => $targets) {
            foreach ($targets as $to) {
                $lines[] = "  {$from} --> {$to}";
            }
            if ($targets === []) {
                $lines[] = "  {$from} --> [*]";
            }
        }
        return implode("\n", $lines) . "\n";
    }

    /** Mapa coherente: estados sin comas y todo destino definido como estado. */
    private static function assertSteps(array $steps): void
    {
        if ($steps === []) {
            throw new \InvalidArgumentException('Rules::transition: el mapa de estados está vacío.');
        }
        foreach ($steps as $from => $targets) {
            if (!is_string($from) || $from === '' || str_contains($from, ',') || !is_array($targets)) {
                throw new \InvalidArgumentException("Rules::transition: estado no válido '{$from}'.");
            }
            foreach ($targets as $to) {
                if (!is_string($to) || !array_key_exists($to, $steps)) {
                    throw new \InvalidArgumentException("Rules::transition: '{$from}' apunta a '{$to}', que no está definido como estado.");
                }
            }
        }
    }

    /**
     * Reglas propias, usables por nombre en cualquier endpoint ('required|slug').
     * bootstrap.php las registra al arrancar. Formato: nombre => [comprobación, mensaje].
     * La comprobación recibe ($value, ?string $param, array $input) y devuelve true si es válido.
     * @return array<string, array{0: callable, 1: string}>
     */
    public static function custom(): array
    {
        return [
            'slug' => [
                fn ($value) => is_string($value) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) === 1,
                'El campo :field solo admite minúsculas, números y guiones.',
            ],
        ];
    }

    private static function presence(bool $required): string
    {
        return $required ? 'required' : 'optional';
    }
}
