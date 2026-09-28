<?php

namespace App\Http\Requests\Hrms\Lifecycle;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Lifecycle/HRMS — waiving a checklist item.
 *
 * The reason is required and has no default: a waived item without one reads
 * as skipped, and “skipped” is a different status with a different meaning.
 * Whether the caller may waive *this* item (mandatory needs manage) is the
 * service’s call, where the mandatory definition lives.
 */
class WaiveTaskRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
