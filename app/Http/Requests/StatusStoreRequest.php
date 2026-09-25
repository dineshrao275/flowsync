<?php

namespace App\Http\Requests;

use App\Enums\TaskStatusCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StatusStoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::enum(TaskStatusCategory::class)],
            'color' => ['nullable', 'string', 'max:16'],
            'position' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
