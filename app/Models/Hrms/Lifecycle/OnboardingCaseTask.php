<?php

namespace App\Models\Hrms\Lifecycle;

use App\Enums\Hrms\CaseTaskStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Lifecycle/HRMS — one checklist item running inside an onboarding case.
 *
 * A snapshot, not a reference: `category` and `owner_scope` are the template
 * values as plain strings, and `template_task_id` is nullable for ad-hoc
 * items added mid-case. Editing the template after this row exists changes
 * future hires, never this one.
 *
 * @property int $id
 * @property int $case_id
 * @property int|null $template_task_id
 * @property string $title
 * @property string|null $description
 * @property string $category
 * @property string $owner_scope
 * @property int|null $owner_employee_id
 * @property Carbon|null $due_date
 * @property CaseTaskStatus $status
 * @property Carbon|null $completed_at
 * @property int|null $completed_by
 * @property string|null $note
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read OnboardingCase $case
 * @property-read Employee|null $owner
 */
class OnboardingCaseTask extends Model
{
    protected $table = 'onboarding_case_tasks';

    protected $fillable = [
        'case_id',
        'template_task_id',
        'title',
        'description',
        'category',
        'owner_scope',
        'owner_employee_id',
        'due_date',
        'status',
        'completed_at',
        'completed_by',
        'note',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'case_id' => 'integer',
            'template_task_id' => 'integer',
            'owner_employee_id' => 'integer',
            'due_date' => 'date',
            'status' => CaseTaskStatus::class,
            'completed_at' => 'datetime',
            'completed_by' => 'integer',
            'position' => 'integer',
        ];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(OnboardingCase::class, 'case_id');
    }

    public function templateTask(): BelongsTo
    {
        return $this->belongsTo(OnboardingTemplateTask::class, 'template_task_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_employee_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** @param Builder<OnboardingCaseTask> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNotIn('status', [CaseTaskStatus::Done->value, CaseTaskStatus::Waived->value]);
    }
}
