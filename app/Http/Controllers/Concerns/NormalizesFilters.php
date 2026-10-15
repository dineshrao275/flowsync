<?php

namespace App\Http\Controllers\Concerns;

/**
 * Drops unset query filters before they reach a service. `clean()` removes
 * nulls only; `cleanBlank()` also removes empty strings.
 */
trait NormalizesFilters
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function clean(array $filters): array
    {
        return array_filter($filters, fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function cleanBlank(array $filters): array
    {
        return array_filter($filters, fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
