<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Public-site link target: a same-site path, a mailto, or an absolute
 * http(s) URL. `javascript:` and friends never belong in nav/footer markup.
 */
class SiteHref implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a string.');

            return;
        }

        $ok = str_starts_with($value, '/')
            || str_starts_with($value, 'mailto:')
            || str_starts_with($value, 'https://')
            || str_starts_with($value, 'http://');

        if (! $ok) {
            $fail('The :attribute must be a site path, mailto:, or http(s) URL.');
        }
    }
}
