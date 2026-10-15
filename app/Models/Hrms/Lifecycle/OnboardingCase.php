<?php

namespace App\Models\Hrms\Lifecycle;

use App\Enums\Hrms\OnboardingCaseStatus;
use App\Models\Hrms\Employee\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Lifecycle/HRMS — one onboarding run for one person.
 *
 * One row per employee, ever (`employee_id` is unique): a rehire reopens
 * rather than duplicates. The tasks are materialised copies, so this row
 * points at the template it started from without depending on it — deleting
 * the template nulls `template_id` and the running case carries on.
 *
 * @property int $id
 * @property int $employee_id
 * @property int|null $template_id
 * @property OnboardingCaseStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Employee $employee
 * @property-read OnboardingTemplate|null $template
 * @property-read Collection<int, OnboardingCaseTask> $tasks
 */
class OnboardingCase extends Model
{
    use SoftDeletes;

    protected $table = 'onboarding_cases';

    protected $fillable = [
        'employee_id',
        'template_id',
        'status',
        'started_at',
        'completed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'template_id' => 'integer',
            'status' => OnboardingCaseStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(OnboardingTemplate::class, 'template_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(OnboardingCaseTask::class, 'case_id')->orderBy('position')->orderBy('id');
    }

    /** @param Builder<OnboardingCase> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [OnboardingCaseStatus::NotStarted->value, OnboardingCaseStatus::InProgress->value]);
    }
}
