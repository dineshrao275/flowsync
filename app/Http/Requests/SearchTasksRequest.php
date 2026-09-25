<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchTasksRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:200'],
            'status_id' => ['nullable', 'integer'],
            'priority_id' => ['nullable', 'integer'],
            'assignee_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'workspace_id' => ['nullable', 'integer'],
            'label_id' => ['nullable', 'integer'],
            'due_from' => ['nullable', 'date'],
            'due_to' => ['nullable', 'date'],
            'assignee' => ['nullable', 'in:me'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
