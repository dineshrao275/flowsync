<?php

namespace App\Http\Controllers\Concerns;

/**
 * Validation rules for the numeric plan quotas in `config/subscriptions.php`
 * (`limits`). Shared by the plan catalog endpoints and the per-tenant override
 * so a super admin can never write a non-numeric cap from the UI.
 */
trait ValidatesResourceLimits
{
    /**
     * @return array<string, array<int, string>>
     */
    protected function resourceLimitRules(string $prefix = 'limits'): array
    {
        $rules = [];

        foreach (config('subscriptions.limits', []) as $key) {
            $rules["{$prefix}.{$key}"] = ['nullable', 'integer', 'min:0'];
        }

        $rules["{$prefix}.modules"] = ['nullable', 'array'];
        $rules["{$prefix}.modules.*"] = ['string'];

        return $rules;
    }

    /**
     * Drop unset/blank caps so a missing key keeps meaning "unlimited"
     * (TenantLimits reads a null limit as no cap) instead of persisting `""`.
     *
     * @param  array<string, mixed>|null  $input
     * @return array<string, mixed>|null
     */
    protected function cleanResourceLimits(?array $input, string $prefix = 'limits'): ?array
    {
        if ($input === null) {
            return null;
        }

        $clean = [];

        foreach ($this->resourceLimitRules($prefix) as $key => $_rules) {
            if (str_ends_with($key, '.*')) {
                continue;
            }

            $short = substr($key, strlen($prefix) + 1);

            if (! array_key_exists($short, $input)) {
                continue;
            }

            $value = $input[$short];

            if ($value === null || $value === '') {
                continue;
            }

            $clean[$short] = $short === 'modules' ? array_values($value) : (int) $value;
        }

        return $clean ?: null;
    }
}
