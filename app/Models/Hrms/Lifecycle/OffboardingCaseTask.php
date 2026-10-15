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
 * Lifecycle/HRMS — one checklist item running inside an offboarding case.
 *
 * The onboarding shape plus linkage: `asset_id` and `expense_claim_id` are
 * bare integers until P14/P8 exist to constrain them, and the clearance
 * counters treat an open asset-category task as a pending asset until the
 * register can say otherwise.
 *
 * @property int $id
 * @property int $case_id
 * @property string $title
 * @property string|null $description
 * @property int|null $asset_id
 * @property int|null $expense_claim_id
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
 * @property-read OffboardingCase $case
 * @property-read Employee|null $owner
 */
class OffboardingCaseTask extends Model
{
    protected $table = 'offboarding_case_tasks';

    protected $fillable = [
        'case_id',
        'title',
        'description',
        'asset_id',
        'expense_claim_id',
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
            'asset_id' => 'integer',
            'expense_claim_id' => 'integer',
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
        return $this->belongsTo(OffboardingCase::class, 'case_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_employee_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** @param Builder<OffboardingCaseTask> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNotIn('status', [CaseTaskStatus::Done->value, CaseTaskStatus::Waived->value]);
    }
}
