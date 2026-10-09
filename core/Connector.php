<?php
declare(strict_types=1);
namespace Core;

use Core\RouteRegistry;
use Core\Validator;
use Core\Container;
use Core\Endpoint;

class Connector
{
    private const MAX_DEPTH = 10;

    public function executeChain(array $targets, array $input, array &$context, int $depth = 0): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \RuntimeException("Maximum connection depth (".self::MAX_DEPTH.") exceeded. Possible loop.");
        }

        $currentInput = $input;

        foreach ($targets as $connection) {
            $targetName = is_array($connection) ? $connection['target'] : $connection;
            $condition = is_array($connection) ? ($connection['when'] ?? null) : null;

            if ($condition && !call_user_func($condition, $currentInput)) {
                continue;
            }

            if (isset($context['visited'][$targetName])) {
                throw new \RuntimeException("Circular connection detected: {$targetName}");
            }
            $context['visited'][$targetName] = true;

            $route = RouteRegistry::getInstance()->findByName($targetName);
            if (!$route) {
                throw new \RuntimeException("Target endpoint not found: {$targetName}");
            }

            $validatedData = $this->prepareInputForRoute($route, $currentInput, $context);

            if (!empty($route['guards'])) {
                foreach ($route['guards'] as $guard) {
                    if (!call_user_func($guard, $validatedData)) {
                        throw new \RuntimeException("Guard failed in connected route '{$targetName}'");
                    }
                }
            }

            $handler = Endpoint::resolveHandler($route['callback']);

            $args = [$validatedData];
            foreach ($route['uses'] ?? [] as $serviceClass) {
                $args[] = Container::resolve($serviceClass);
            }
            $handlerResponse = call_user_func_array($handler, $args);

            if (!empty($route['connectsTo'])) {
                $handlerResponse = $this->executeChain(
                    $route['connectsTo'],
                    $handlerResponse,
                    $context,
                    $depth + 1
                );
            }

            $currentInput = $handlerResponse;
        }

        return $currentInput;
    }

    private function prepareInputForRoute(array $route, array $input, array &$context): array
    {
        $globalFields = array_filter($input, fn($key) => str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);

        $inputForValidation = $input;
        $inputSources = array_fill_keys(array_keys($input), 'chain');

        $validatedFields = [];

        if (!empty($route['expects'])) {
            $fieldsToValidate = array_diff_key($input, $globalFields);
            $sourcesToValidate = array_diff_key($inputSources, $globalFields);

            $validator = new Validator($fieldsToValidate, $route['expects'], $sourcesToValidate, false);
            if (!$validator->passes()) {
                throw new \RuntimeException("Validation failed in connected route '{$route['name']}': " . json_encode($validator->errors()));
            }
            $validatedFields = $validator->validated();
        } else {
            $validatedFields = $input;
        }

        return array_merge($validatedFields, $globalFields);
    }
}