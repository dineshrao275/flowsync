<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Clone payload (P4.6): optional new title, optional target project (default: the same project). */
class TaskCloneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'target_project_id' => ['nullable', 'integer'],
            'include_subtasks' => ['nullable', 'boolean'],
            'include_checklist' => ['nullable', 'boolean'],
        ];
    }
}
