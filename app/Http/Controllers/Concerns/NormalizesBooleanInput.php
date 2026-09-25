<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Query strings and form payloads deliver booleans as "true"/"false"/"1"/"0"
 * (axios serializes `trashed=false` into the URL, unchecked boxes send strings,
 * …) and Laravel's `boolean` rule only accepts real booleans and 1/0. Values are
 * normalized *before* validation so the rule can stay strict, and anything that
 * is not boolean-ish is passed through untouched so validation still 422s.
 */
trait NormalizesBooleanInput
{
    /**
     * @param  array<string, mixed>  $input
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    protected function normalizeBooleans(array $input, array $keys): array
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            $value = $input[$key];

            if ($value === null || $value === '') {
                $input[$key] = null;

                continue;
            }

            $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            $input[$key] = $filtered ?? $value;
        }

        return $input;
    }

    /**
     * Normalize the given keys on the request itself (query + body) so
     * `$request->validate()` sees real booleans.
     */
    protected function normalizeRequestBooleans(Request $request, array $keys): void
    {
        $request->merge($this->normalizeBooleans($request->all(), $keys));
    }
}
