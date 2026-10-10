<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Move-to-another-project payload (P4.6): the target and an optional source->target status map. */
class TaskMoveProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_project_id' => ['required', 'integer'],
            'status_map' => ['nullable', 'array'],
            'status_map.*' => ['integer'],
        ];
    }
}
