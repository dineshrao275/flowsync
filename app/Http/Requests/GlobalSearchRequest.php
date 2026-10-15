<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GlobalSearchRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'workspace_limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'project_limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'task_limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'user_limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'employee_limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
