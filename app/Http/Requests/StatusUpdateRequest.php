<?php

namespace App\Http\Requests;

use App\Enums\TaskStatusCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StatusUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', Rule::enum(TaskStatusCategory::class)],
            'color' => ['nullable', 'string', 'max:16'],
            'position' => ['nullable', 'integer', 'min:1'],
            'is_done' => ['nullable', 'boolean'],
        ];
    }
}
