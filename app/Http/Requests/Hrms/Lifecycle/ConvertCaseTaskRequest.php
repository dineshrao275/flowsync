<?php

namespace App\Http\Requests\Hrms\Lifecycle;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Lifecycle/HRMS — converting a checklist item into a project task.
 *
 * One id, tenant-scoped by the connection: the project the task will live
 * in. Whether the caller may work the item is the item policy's call and
 * whether they may file into the project is the project policy's — this
 * answers neither, only that the project exists here.
 */
class ConvertCaseTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'exists:projects,id'],
        ];
    }
}
