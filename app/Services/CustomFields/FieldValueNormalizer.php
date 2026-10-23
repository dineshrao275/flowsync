<?php

declare(strict_types=1);

namespace App\Services\CustomFields;

use App\Models\CustomFieldDefinition;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Turns one raw submitted value into the stored form for a custom-field type (trimmed/limited
 * text, numbers, ISO dates, option membership, validated url/email, a same-tenant user id), or
 * throws InvalidArgumentException with a translated reason. Stateless: CustomFieldService owns
 * definitions, persistence and filtering; this owns what a valid value is.
 */
class FieldValueNormalizer
{
    /** @return mixed the normalized value, or null when empty */
    public function normalize(CustomFieldDefinition $definition, mixed $raw): mixed
    {
        if (self::isEmpty($raw)) {
            return null;
        }

        return match ($definition->type) {
            'text' => $this->string($raw, 255),
            'textarea' => $this->string($raw, 5000),
            'number', 'currency' => $this->number($raw),
            'date' => $this->date($raw, false),
            'datetime' => $this->date($raw, true),
            'boolean' => $this->boolean($raw),
            'select' => $this->select($definition, $raw),
            'multiselect' => $this->multiselect($definition, $raw),
            'url' => $this->url($raw),
            'email' => $this->email($raw),
            'user' => $this->user($raw),
            default => throw new \InvalidArgumentException(__('Unsupported field type.')),
        };
    }

    // -- type rules --------------------------------------------------------------------------

    private function string(mixed $raw, int $max): string
    {
        if (! is_scalar($raw)) {
            throw new \InvalidArgumentException(__('Enter text.'));
        }
        $value = trim((string) $raw);

        if (mb_strlen($value) > $max) {
            throw new \InvalidArgumentException(__('Must be at most :max characters.', ['max' => $max]));
        }

        return $value;
    }

    private function number(mixed $raw): int|float
    {
        if (! is_numeric($raw)) {
            throw new \InvalidArgumentException(__('Enter a number.'));
        }

        return $raw + 0;
    }

    private function date(mixed $raw, bool $withTime): string
    {
        try {
            $date = Carbon::parse((string) $raw);
        } catch (\Throwable) {
            throw new \InvalidArgumentException(__('Enter a valid date.'));
        }

        return $withTime ? $date->format('Y-m-d\TH:i') : $date->format('Y-m-d');
    }

    private function boolean(mixed $raw): bool
    {
        $value = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($value === null) {
            throw new \InvalidArgumentException(__('Choose yes or no.'));
        }

        return $value;
    }

    private function select(CustomFieldDefinition $definition, mixed $raw): string
    {
        $value = (string) $raw;

        if (! in_array($value, self::optionValues($definition), true)) {
            throw new \InvalidArgumentException(__('Choose one of the listed options.'));
        }

        return $value;
    }

    /** @return list<string> */
    private function multiselect(CustomFieldDefinition $definition, mixed $raw): array
    {
        if (! is_array($raw)) {
            throw new \InvalidArgumentException(__('Choose from the listed options.'));
        }

        $allowed = self::optionValues($definition);
        $values = array_values(array_unique(array_map('strval', $raw)));

        foreach ($values as $value) {
            if (! in_array($value, $allowed, true)) {
                throw new \InvalidArgumentException(__('Choose from the listed options.'));
            }
        }

        return $values;
    }

    private function url(mixed $raw): string
    {
        $value = $this->string($raw, 500);

        if (filter_var($value, FILTER_VALIDATE_URL) === false || ! in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new \InvalidArgumentException(__('Enter a valid http(s) URL.'));
        }

        return $value;
    }

    private function email(mixed $raw): string
    {
        $value = $this->string($raw, 255);

        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException(__('Enter a valid email address.'));
        }

        return $value;
    }

    private function user(mixed $raw): int
    {
        if (! is_numeric($raw) || ! User::query()->whereKey((int) $raw)->exists()) {
            throw new \InvalidArgumentException(__('Choose an existing user.'));
        }

        return (int) $raw;
    }

    public static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /** @return list<string> */
    public static function optionValues(CustomFieldDefinition $definition): array
    {
        return array_map(fn ($o) => (string) $o['value'], $definition->options ?? []);
    }
}
