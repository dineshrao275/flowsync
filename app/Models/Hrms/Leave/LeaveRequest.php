<?php

namespace App\Models\Hrms\Leave;

use App\Enums\Hrms\LeaveHalf;
use App\Enums\Hrms\LeaveRequestStatus;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Shared\Approval;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Leave/HRMS — one leave ask and its lifecycle.
 *
 * `total_days` counts workdays only: the day split (`days`) carries every
 * calendar date in range with its flags, and week-offs/holidays are
 * excluded from the total the balance is checked against. The `availed`
 * ledger row is written on approval, never on submission — a pending ask
 * *reserves* balance (see `LeaveRequestStatus::reservesBalance()`), it does
 * not post it.
 */
class LeaveRequest extends Model
{
    use SoftDeletes;

    protected $table = 'leave_requests';

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'from_date',
        'to_date',
        'from_half',
        'to_half',
        'total_days',
        'reason',
        'contact_during_leave',
        'document_id',
        'status',
        'approval_id',
        'decided_at',
        'decided_by_user_id',
        'cancel_reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'leave_type_id' => 'integer',
            'from_date' => 'date',
            'to_date' => 'date',
            'from_half' => LeaveHalf::class,
            'to_half' => LeaveHalf::class,
            'total_days' => 'decimal:2',
            'document_id' => 'integer',
            'status' => LeaveRequestStatus::class,
            'approval_id' => 'integer',
            'decided_at' => 'datetime',
            'decided_by_user_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    /** @return HasMany<LeaveRequestDay, $this> */
    public function days(): HasMany
    {
        return $this->hasMany(LeaveRequestDay::class, 'leave_request_id')->orderBy('date');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'document_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpen(): bool
    {
        return ! $this->status->isTerminal();
    }
}
