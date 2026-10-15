<?php

namespace App\Models\Hrms\Lifecycle;

use App\Enums\Hrms\OffboardingCaseStatus;
use App\Enums\Hrms\OffboardingReason;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Lifecycle/HRMS — one exit run for one person.
 *
 * Deliberately NOT unique on `employee_id`: people can leave twice, and each
 * exit is its own case with its own clearance. The `exit_interview_notes`
 * live here rather than on the employee because they belong to the episode —
 * a rehire’s second exit must not inherit the first one’s interview.
 *
 * @property int $id
 * @property int $employee_id
 * @property Carbon $last_working_day
 * @property OffboardingReason $reason
 * @property OffboardingCaseStatus $status
 * @property int|null $notice_period_days
 * @property Carbon|null $exit_interview_at
 * @property string|null $exit_interview_notes
 * @property Carbon|null $resignation_received_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Employee $employee
 * @property-read Collection<int, OffboardingCaseTask> $tasks
 * @property-read ExitClearance|null $clearance
 */
class OffboardingCase extends Model
{
    use SoftDeletes;

    protected $table = 'offboarding_cases';

    protected $fillable = [
        'employee_id',
        'last_working_day',
        'reason',
        'status',
        'notice_period_days',
        'exit_interview_at',
        'exit_interview_notes',
        'resignation_received_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'last_working_day' => 'date',
            'reason' => OffboardingReason::class,
            'status' => OffboardingCaseStatus::class,
            'notice_period_days' => 'integer',
            'exit_interview_at' => 'datetime',
            'resignation_received_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(OffboardingCaseTask::class, 'case_id')->orderBy('position')->orderBy('id');
    }

    public function clearance(): HasOne
    {
        return $this->hasOne(ExitClearance::class, 'case_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param Builder<OffboardingCase> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [OffboardingCaseStatus::Initiated->value, OffboardingCaseStatus::InProgress->value]);
    }
}
