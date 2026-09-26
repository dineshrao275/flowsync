<?php

namespace App\Models\Hrms\Shared;

use App\Enums\Hrms\ApprovalStatus;
use App\Enums\Hrms\ApprovalStepStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Shared/HRMS — one approval request, walked step by step.
 *
 * Generic by design: `approvable_*` is polymorphic, so leave, expenses, salary
 * revisions, offboarding clearance and documents all reuse this engine instead
 * of each growing their own approval columns (D2.4).
 *
 * @property int $id
 * @property string $approvable_type
 * @property int $approvable_id
 * @property string $subject
 * @property string $action
 * @property ApprovalStatus $status
 * @property int|null $requested_by_user_id
 * @property int|null $requested_by_employee_id
 * @property int $current_step
 * @property Carbon|null $due_at
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by_user_id
 * @property string|null $decision_note
 * @property array<string, mixed>|null $meta
 */
class Approval extends Model
{
    use SoftDeletes;

    protected $table = 'approvals';

    protected $fillable = [
        'approvable_type',
        'approvable_id',
        'subject',
        'action',
        'status',
        'requested_by_user_id',
        'requested_by_employee_id',
        'current_step',
        'due_at',
        'resolved_at',
        'resolved_by_user_id',
        'decision_note',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'status' => ApprovalStatus::class,
            'current_step' => 'integer',
            'meta' => 'array',
            'due_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Model, $this> */
    public function approvable(): BelongsTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<ApprovalStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class)->orderBy('step_order');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    /**
     * The step currently awaiting a decision, if the flow is still open.
     *
     * A method rather than a relation: the answer depends on both the step's
     * own status and this approval's `current_step`, which a relation's
     * constraints cannot read off the parent.
     */
    public function currentStepRecord(): ?ApprovalStep
    {
        if (! $this->isOpen()) {
            return null;
        }

        return $this->steps()
            ->where('step_order', $this->current_step)
            ->where('status', ApprovalStepStatus::Pending)
            ->first();
    }

    public function isOpen(): bool
    {
        return $this->status === ApprovalStatus::Pending;
    }

    /** @param Builder<Approval> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', ApprovalStatus::Pending);
    }
}
