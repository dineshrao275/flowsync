<?php

namespace App\Http\Requests;

use App\Enums\TaskDependencyType;
use Illuminate\Foundation\Http\FormRequest;

class DependencyStoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'depends_on_task_id' => ['required', 'integer'],
            'type' => ['required', 'in:'.implode(',', array_column(TaskDependencyType::cases(), 'value'))],
        ];
    }
}
