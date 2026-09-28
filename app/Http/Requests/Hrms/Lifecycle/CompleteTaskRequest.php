<?php

namespace App\Http\Requests\Hrms\Lifecycle;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Lifecycle/HRMS — marking a checklist item done, with an optional note.
 *
 * The note is free text on purpose: “done, laptop collected by facilities”
 * is operational context, not a decision, and decisions (waives) have their
 * own request with a required reason.
 */
class CompleteTaskRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
