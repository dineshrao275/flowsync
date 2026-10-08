<?php

namespace App\Http\Requests\Hrms;

use App\Enums\Hrms\TaskLinkKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * TaskLink/HRMS — filing a bridge row.
 *
 * Validates the HTTP shape only: a real employee, a known kind, an
 * optional note. Whether the caller may touch the task is the
 * controller's call (project-role `tasks.edit`), and whether they may
 * name the employee is the employee policy's — a request class cannot
 * answer either, so it answers neither.
 */
class TaskLinkStoreRequest extends FormRequest
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
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'kind' => ['required', Rule::enum(TaskLinkKind::class)],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
