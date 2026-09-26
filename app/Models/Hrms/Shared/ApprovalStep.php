<?php

namespace App\Models\Hrms\Shared;

use App\Enums\Hrms\ApprovalStepStatus;
use App\Enums\Hrms\ApproverType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Shared/HRMS — one step in an {@see Approval} chain.
 *
 * Steps are 1-based and dense. The approver is expressed as a *rule*
 * (`approver_type` + the matching column) rather than a fixed user, so a
 * re-org changes who must act without rewriting history.
 *
 * @property int $id
 * @property int $approval_id
 * @property int $step_order
 * @property ApproverType $approver_type
 * @property int|null $approver_role_id
 * @property int|null $approver_user_id
 * @property int|null $approver_employee_id
 * @property ApprovalStepStatus $status
 * @property Carbon|null $acted_at
 * @property int|null $acted_by_user_id
 * @property string|null $note
 */
class ApprovalStep extends Model
{
    use HasFactory;

    protected $table = 'approval_steps';

    protected $fillable = [
        'approval_id',
        'step_order',
        'approver_type',
        'approver_role_id',
        'approver_user_id',
        'approver_employee_id',
        'status',
        'acted_at',
        'acted_by_user_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'approver_type' => ApproverType::class,
            'status' => ApprovalStepStatus::class,
            'step_order' => 'integer',
            'acted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Approval, $this> */
    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    /** @return BelongsTo<Role, $this> */
    public function approverRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'approver_role_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approverUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by_user_id');
    }

    public function isActionable(): bool
    {
        return $this->status->isActionable();
    }

    /**
     * Whether this step is waiting on somebody at all.
     *
     * A `role`/`user` step with no target, or a manager/head step whose
     * requester has nobody to escalate to, has no candidate. The engine skips
     * it rather than blocking the chain forever.
     */
    public function hasCandidate(): bool
    {
        return match ($this->approver_type) {
            ApproverType::Role => $this->approver_role_id !== null,
            ApproverType::User, ApproverType::Manager, ApproverType::DepartmentHead => $this->approver_user_id !== null,
        };
    }

    /**
     * Whether the given user is allowed to act on this step.
     *
     * `role` matches the assigned role; every other type matches the user
     * resolved into `approver_user_id` when the flow was requested. Manager and
     * department-head steps therefore stay generic — resolving *who* the
     * requester's manager is belongs to the context that owns the employee
     * graph, not to this engine.
     */
    public function canBeActedBy(User $user): bool
    {
        if (! $this->isActionable()) {
            return false;
        }

        return match ($this->approver_type) {
            ApproverType::Role => $user->hasRole($this->approverRole?->slug ?? ''),
            default => $this->approver_user_id === $user->id,
        };
    }
}
