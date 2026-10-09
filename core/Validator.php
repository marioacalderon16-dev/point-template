<?php
declare(strict_types=1);
namespace Core;

class Validator
{
    private $input;
    private $rules;
    private $sources;
    private $errors = [];
    private $validated = [];
    private $strict = false;

    public function __construct(array $input, array $rules, array $sources = [], bool $strict = false)
    {
        $this->input = $input;
        $this->rules = $rules;
        $this->sources = $sources;
        $this->strict = $strict;
    }

    public function passes(): bool
    {
        $this->errors = [];
        $this->validated = [];

        if ($this->strict) {
            $this->validateNoExtraFields();
        }

        $fieldsToValidate = [];

        foreach ($this->rules as $field => $ruleString) {
            // Sintaxis array permite reglas con '|' interno, ej: ['required', 'regex:/^(a|b)$/']
            $rules = is_array($ruleString) ? array_values($ruleString) : explode('|', $ruleString);

            $sourceRule = $this->findRuleWithPrefix($rules, 'source');
            $explicitSourceCheck = false;
            $allowedSources = [];

            if ($sourceRule) {
                $explicitSourceCheck = true;
                [, $sourceParam] = explode(':', $sourceRule, 2);
                $allowedSources = array_map('trim', explode(',', $sourceParam));
            }

            $actualSource = $this->sources[$field] ?? 'unknown';

            if ($actualSource === 'middleware' && (!$explicitSourceCheck || !in_array('middleware', $allowedSources, true))) {
                $this->validated[$field] = $this->input[$field] ?? null;
                continue;
            }

            $fieldsToValidate[$field] = $rules;
        }

        foreach ($fieldsToValidate as $field => $rules) {
            $value = $this->input[$field] ?? null;

            // Campo opcional ausente: aplicar default si existe y saltar el resto de reglas
            if (($value === null || $value === '') && in_array('optional', $rules, true)) {
                $defaultRule = $this->findRuleWithPrefix($rules, 'default');
                if ($defaultRule !== null) {
                    [, $defaultValue] = explode(':', $defaultRule, 2);
                    $this->validated[$field] = $this->parseDefaultValue($defaultValue);
                } else {
                    $this->validated[$field] = null;
                }
                continue;
            }

            foreach ($rules as $rule) {
                if ($this->hasError($field))
                    break;

                $ruleName = $rule;
                $ruleParam = null;
                if (strpos($rule, ':') !== false) {
                    [$ruleName, $ruleParam] = explode(':', $rule, 2);
                }

                $this->applyRule($field, $value, $ruleName, $ruleParam, $rules);
            }

            if (!$this->hasError($field)) {
                $this->validated[$field] = $value;
            }
        }

        return empty($this->errors);
    }

    private function validateNoExtraFields(): void
    {
        $allowedKeys = array_keys($this->rules);
        $extraKeys = [];

        foreach (array_keys($this->input) as $key) {
            if (!in_array($key, $allowedKeys) && ($this->sources[$key] ?? '') !== 'middleware') {
                $extraKeys[] = $key;
            }
        }

        foreach ($extraKeys as $key) {
            $this->addError($key, "Campo no permitido: {$key}");
        }
    }

    private function applyRule(string $field, &$value, string $rule, ?string $param, array $allRules): void
    {
        switch ($rule) {
            case 'trim':
                if (is_string($value))
                    $value = trim($value);
                break;
            case 'lowercase':
                if (is_string($value))
                    $value = strtolower($value);
                break;
            case 'uppercase':
                if (is_string($value))
                    $value = strtoupper($value);
                break;
            case 'strip_tags':
                if (is_string($value))
                    $value = strip_tags($value);
                break;
            case 'required':
                if ($value === null || $value === '') {
                    $this->addError($field, "El campo {$field} es obligatorio.");
                }
                break;
            case 'optional':
                // El caso ausente se maneja antes del loop de reglas
                break;
            case 'string':
                if ($value !== null && !is_string($value)) {
                    $value = (string) $value;
                }
                break;
            case 'integer':
                if ($value !== null) {
                    if (is_numeric($value) && (string) (int) $value === (string) $value) {
                        $value = (int) $value;
                    } else {
                        $this->addError($field, "El campo {$field} debe ser un número entero.");
                    }
                }
                break;
            case 'numeric':
                if ($value !== null && !is_numeric($value)) {
                    $this->addError($field, "El campo {$field} debe ser un número.");
                }
                break;
            case 'boolean':
                if ($value !== null) {
                    $parsed = $this->parseBoolean($value);
                    if ($parsed === null) {
                        $this->addError($field, "El campo {$field} debe ser booleano.");
                    } else {
                        $value = $parsed;
                    }
                }
                break;
            case 'array':
                if ($value !== null && !is_array($value)) {
                    $this->addError($field, "El campo {$field} debe ser un array.");
                }
                break;
            case 'email':
                if ($value !== null && !is_string($value)) {
                    $this->addError($field, "El campo {$field} debe ser una cadena.");
                } elseif ($value !== null && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->addError($field, "El campo {$field} debe ser un email válido.");
                }
                break;
            case 'min':
                if ($param !== null && $value !== null) {
                    $min = (float) $param;
                    if (is_array($value)) {
                        if (count($value) < $min) {
                            $this->addError($field, "El campo {$field} debe tener al menos {$min} elementos.");
                        }
                    } elseif (is_string($value)) {
                        if (strlen($value) < $min) {
                            $this->addError($field, "El campo {$field} debe tener al menos {$min} caracteres.");
                        }
                    } elseif (is_numeric($value)) {
                        if ((float) $value < $min) {
                            $this->addError($field, "El campo {$field} debe ser al menos {$min}.");
                        }
                    }
                }
                break;
            case 'max':
                if ($param !== null && $value !== null) {
                    $max = (float) $param;
                    if (is_array($value)) {
                        if (count($value) > $max) {
                            $this->addError($field, "El campo {$field} no debe tener más de {$max} elementos.");
                        }
                    } elseif (is_string($value)) {
                        if (strlen($value) > $max) {
                            $this->addError($field, "El campo {$field} no debe exceder {$max} caracteres.");
                        }
                    } elseif (is_numeric($value)) {
                        if ((float) $value > $max) {
                            $this->addError($field, "El campo {$field} no debe ser mayor que {$max}.");
                        }
                    }
                }
                break;
            case 'in':
                if ($param !== null && $value !== null) {
                    $allowed = array_map('trim', explode(',', $param));
                    // Comparar como string para que funcione tras casts (integer|in:1,2)
                    if (!is_scalar($value) || !in_array((string) $value, $allowed, true)) {
                        $this->addError($field, "El campo {$field} debe ser uno de: " . implode(', ', $allowed));
                    }
                }
                break;
            case 'not_in':
                if ($param !== null && $value !== null) {
                    $forbidden = array_map('trim', explode(',', $param));
                    if (is_scalar($value) && in_array((string) $value, $forbidden, true)) {
                        $this->addError($field, "El campo {$field} no puede ser: " . implode(', ', $forbidden));
                    }
                }
                break;
            case 'list_in':
                if ($param !== null && $value !== null && is_string($value)) {
                    $allowed = array_map('trim', explode(',', $param));
                    $items = array_map('trim', explode(',', $value));
                    $invalid = array_diff($items, $allowed);
                    if (!empty($invalid)) {
                        $this->addError($field, "Valores no permitidos en {$field}: " . implode(', ', $invalid));
                    }
                }
                break;
            case 'source':
                if ($param !== null) {
                    $allowedSources = explode(',', $param);
                    $actualSource = $this->sources[$field] ?? 'unknown';
                    if (!in_array($actualSource, $allowedSources, true)) {
                        $this->addError($field, "El campo {$field} debe provenir de: " . implode(', ', $allowedSources) . ". Viene de: {$actualSource}.");
                    }
                }
                break;
            case 'date':
                if ($value !== null) {
                    if (!is_string($value)) {
                        $this->addError($field, "El campo {$field} debe ser una cadena.");
                    } else {
                        $format = $param ?: 'Y-m-d';
                        $date = \DateTime::createFromFormat($format, $value);
                        if ($date === false || $date->format($format) !== $value) {
                            $this->addError($field, "El campo {$field} debe ser una fecha válida en formato {$format}.");
                        }
                    }
                }
                break;
            case 'unique':
                if ($value !== null && $value !== '') {
                    if ($param === null) {
                        $this->addError($field, "Regla 'unique' requiere parámetro (ej: unique:usuarios,email).");
                        break;
                    }

                    $parts = array_map('trim', explode(',', $param));
                    $table = $parts[0] ?? null;
                    $column = $parts[1] ?? null;
                    $exceptValue = $parts[2] ?? null;
                    $idColumn = $parts[3] ?? 'id';

                    if (!$table || !$column) {
                        $this->addError($field, "Formato inválido para 'unique'. Usa: unique:tabla,columna[,except,idColumn]");
                        break;
                    }

                    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column) || !preg_match('/^[a-zA-Z0-9_]+$/', $idColumn)) {
                        $this->addError($field, "Caracteres inválidos en regla 'unique'.");
                        break;
                    }

                    if ($exceptValue && strpos($exceptValue, '{$') !== false) {
                        if (preg_match('/\{\$(\w+)\}/', $exceptValue, $matches)) {
                            $varName = $matches[1];
                            $exceptValue = $this->validated[$varName] ?? $this->input[$varName] ?? null;
                        }
                    }

                    $query = DB::table($table)->where($column, $value);

                    if ($exceptValue !== null) {
                        $query->where($idColumn, '!=', $exceptValue);
                    }

                    if ($query->exists()) {
                        $this->addError($field, "El {$field} ya está en uso.");
                    }
                }
                break;
            case 'exists':
                if ($value !== null && $value !== '') {
                    if ($param === null) {
                        $this->addError($field, "Regla 'exists' requiere parámetro (ej: exists:roles,id).");
                        break;
                    }

                    $parts = array_map('trim', explode(',', $param));
                    if (count($parts) !== 2) {
                        $this->addError($field, "Formato inválido para 'exists'. Usa: exists:tabla,columna");
                        break;
                    }

                    [$table, $column] = $parts;

                    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
                        $this->addError($field, "Caracteres inválidos en regla 'exists'.");
                        break;
                    }

                    if (!DB::table($table)->where($column, $value)->exists()) {
                        $this->addError($field, "El valor proporcionado para {$field} no es válido.");
                    }
                }
                break;
            case 'url':
                if ($value !== null && (!is_string($value) || !filter_var($value, FILTER_VALIDATE_URL))) {
                    $this->addError($field, "El campo {$field} debe ser una URL válida.");
                }
                break;
            case 'uuid':
                if ($value !== null && (!is_string($value) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value))) {
                    $this->addError($field, "El campo {$field} debe ser un UUID válido.");
                }
                break;
            case 'ip':
                if ($value !== null && (!is_string($value) || !filter_var($value, FILTER_VALIDATE_IP))) {
                    $this->addError($field, "El campo {$field} debe ser una IP válida.");
                }
                break;
            case 'alpha_num':
                if ($value !== null && !ctype_alnum((string) $value)) {
                    $this->addError($field, "El campo {$field} solo puede contener letras y números.");
                }
                break;
            case 'regex':
                if ($param !== null && $value !== null) {
                    if (!is_string($value) || !preg_match($param, $value)) {
                        $this->addError($field, "El formato del campo {$field} no es válido.");
                    }
                }
                break;
            case 'json':
                if ($value !== null && (!is_string($value) || !json_validate($value))) {
                    $this->addError($field, "El campo {$field} debe ser JSON válido.");
                }
                break;
            case 'between':
                if ($param !== null && $value !== null) {
                    $limits = array_map('trim', explode(',', $param));
                    if (count($limits) === 2) {
                        [$min, $max] = array_map('floatval', $limits);
                        $size = is_array($value) ? count($value) : (is_numeric($value) ? (float) $value : strlen((string) $value));
                        if ($size < $min || $size > $max) {
                            $this->addError($field, "El campo {$field} debe estar entre {$limits[0]} y {$limits[1]}.");
                        }
                    }
                }
                break;
            case 'confirmed':
                if ($value !== null && $value !== ($this->input["{$field}_confirmation"] ?? null)) {
                    $this->addError($field, "La confirmación de {$field} no coincide.");
                }
                break;
            case 'each':
                // each:integer, each:email, each:uuid... valida cada elemento del array
                if ($param !== null && $value !== null) {
                    if (!is_array($value)) {
                        $this->addError($field, "El campo {$field} debe ser un array.");
                        break;
                    }
                    foreach ($value as $index => $item) {
                        $this->applyRule("{$field}.{$index}", $item, $param, null, []);
                        $value[$index] = $item;
                    }
                }
                break;
            case 'file':
                if ($value !== null && !$value instanceof \Core\UploadedFile) {
                    $file = \Core\UploadedFile::fromGlobal($field);
                    if ($file === null) {
                        if (in_array('required', $allRules, true)) {
                            $this->addError($field, "El campo {$field} debe ser un archivo.");
                        }
                        $value = null;
                    } elseif (!$file->isValid()) {
                        $this->addError($field, "El archivo {$field} no se subió correctamente.");
                    } else {
                        $value = $file;
                    }
                }
                break;
            case 'max_size':
                if ($param !== null && $value instanceof \Core\UploadedFile) {
                    $maxKb = (int) $param;
                    if ($value->sizeKb() > $maxKb) {
                        $this->addError($field, "El archivo {$field} no debe exceder {$maxKb}KB.");
                    }
                }
                break;
            case 'mime':
                if ($param !== null && $value instanceof \Core\UploadedFile) {
                    $allowed = array_map('trim', explode(',', $param));
                    $ext = $value->extension();
                    if (!in_array($ext, $allowed, true)) {
                        $this->addError($field, "El archivo {$field} debe ser de tipo: " . implode(', ', $allowed) . ".");
                    }
                }
                break;
            case 'default':
                if ($value === null || $value === '') {
                    $value = $this->parseDefaultValue($param);
                }
                break;
            default:
                if (ErrorHandler::isDevelopment()) {
                    error_log("Regla no soportada: '$rule' para '$field'");
                }
                break;
        }
    }

    private function addError(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = [];
        }
        $this->errors[$field][] = $message;
    }

    private function hasError(string $field): bool
    {
        if (!empty($this->errors[$field])) {
            return true;
        }
        // Errores de elementos (each): items.0, items.1...
        foreach ($this->errors as $key => $messages) {
            if (str_starts_with($key, "{$field}.")) {
                return true;
            }
        }
        return false;
    }

    private function findRuleWithPrefix(array $rules, string $prefix): ?string
    {
        foreach ($rules as $rule) {
            if (strpos($rule, $prefix . ':') === 0) {
                return $rule;
            }
        }
        return null;
    }

    private function parseBoolean($value)
    {
        if (is_bool($value))
            return $value;
        if (in_array($value, ['true', '1', 1, 'on', 'yes'], true))
            return true;
        if (in_array($value, ['false', '0', 0, 'off', 'no'], true))
            return false;
        return null;
    }

    private function parseDefaultValue(string $value)
    {
        if ($value === 'true')
            return true;
        if ($value === 'false')
            return false;
        if (is_numeric($value)) {
            return (string) (int) $value === $value ? (int) $value : (float) $value;
        }
        return $value;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function validated(): array
    {
        return $this->validated;
    }
}
