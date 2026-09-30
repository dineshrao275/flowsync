<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Performance/HRMS — creating a review cycle.
 *
 * Create-only: cycles are never edited, only transitioned — a period that
 * moved under priced evidence would rewrite what every goal was measured
 * against. `slug` is server-allocated like every other catalogue name.
 */
class PerformanceCycleRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'anonymity' => ['sometimes', Rule::in(['none', 'reviewer', 'peer'])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
