<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

/**
 * Validates a model-supplied argument object against a tool's JSON Schema subset
 * (string, integer, number, boolean, enum, bounds, required). Strict on purpose: model output is
 * untrusted input, so unknown keys and wrong types are rejected, not coerced into something plausible.
 */
final class ArgumentValidator
{
    /**
     * @param  array<string,mixed>  $schema
     * @param  array<string,mixed>  $args
     * @return array<string,mixed> normalised arguments
     *
     * @throws InvalidToolArguments
     */
    public static function validate(array $schema, array $args): array
    {
        $properties = (array) ($schema['properties'] ?? []);
        $out = [];

        foreach (array_keys($args) as $key) {
            if (! array_key_exists($key, $properties)) {
                throw new InvalidToolArguments("Unknown argument '{$key}'.");
            }
        }

        foreach ((array) ($schema['required'] ?? []) as $required) {
            if (! array_key_exists($required, $args) || $args[$required] === null || $args[$required] === '') {
                throw new InvalidToolArguments("Missing required argument '{$required}'.");
            }
        }

        foreach ($args as $key => $value) {
            if ($value === null) {
                continue;
            }
            $out[$key] = self::value($key, (array) $properties[$key], $value);
        }

        return $out;
    }

    /** @param array<string,mixed> $rule */
    private static function value(string $key, array $rule, mixed $value): mixed
    {
        $type = $rule['type'] ?? 'string';

        $value = match ($type) {
            'integer' => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value)) ? (int) $value : self::fail($key, 'an integer'),
            'number' => is_numeric($value) ? $value + 0 : self::fail($key, 'a number'),
            'boolean' => is_bool($value) ? $value : self::fail($key, 'true or false'),
            default => is_string($value) ? trim($value) : self::fail($key, 'a string'),
        };

        if (isset($rule['enum']) && ! in_array($value, $rule['enum'], true)) {
            throw new InvalidToolArguments("Argument '{$key}' must be one of: ".implode(', ', $rule['enum']).'.');
        }
        if (isset($rule['minimum']) && $value < $rule['minimum']) {
            throw new InvalidToolArguments("Argument '{$key}' must be at least {$rule['minimum']}.");
        }
        if (isset($rule['maximum']) && $value > $rule['maximum']) {
            throw new InvalidToolArguments("Argument '{$key}' must be at most {$rule['maximum']}.");
        }
        if (isset($rule['maxLength']) && is_string($value) && mb_strlen($value) > $rule['maxLength']) {
            throw new InvalidToolArguments("Argument '{$key}' is too long (max {$rule['maxLength']}).");
        }

        return $value;
    }

    private static function fail(string $key, string $expected): never
    {
        throw new InvalidToolArguments("Argument '{$key}' must be {$expected}.");
    }
}
