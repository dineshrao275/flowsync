<?php

namespace App\Http\Requests;

use App\Services\Tasks\TaskBulk;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Bulk edit / assign / transition payload (P4.6). Row-level authorisation happens per task in TaskBulk. */
class TaskBulkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'task_ids' => ['required', 'array', 'min:1', 'max:'.TaskBulk::MAX_TASKS],
            'task_ids.*' => ['integer'],
            'action' => ['required', Rule::in(TaskBulk::ACTIONS)],
            'assignee_id' => ['nullable', 'integer'],
            'status_id' => [Rule::requiredIf($this->input('action') === 'transition'), 'nullable', 'integer'],
            'priority_id' => ['nullable', 'integer'],
            'due_date' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date'],
            'version_id' => ['nullable', 'integer'],
            'epic_id' => ['nullable', 'integer'],
            'story_points' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'estimate_minutes' => ['nullable', 'integer', 'min:0'],
            'labels_add' => ['nullable', 'array'],
            'labels_add.*' => ['integer'],
            'labels_remove' => ['nullable', 'array'],
            'labels_remove.*' => ['integer'],
        ];
    }

    /** The edit fields actually present in the request (an explicit null clears a field). */
    public function fields(): array
    {
        return $this->only(array_merge(TaskBulk::UPDATE_FIELDS, ['assignee_id', 'status_id', 'labels_add', 'labels_remove']));
    }
}
