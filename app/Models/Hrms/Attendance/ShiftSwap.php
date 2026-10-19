<?php

namespace App\Models\Hrms\Attendance;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Shared\Approval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftSwap extends Model
{
    public const PENDING = 'pending';

    public const APPLIED = 'applied';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    protected $table = 'shift_swaps';

    protected $fillable = [
        'requester_employee_id', 'counterparty_employee_id', 'swap_date', 'requester_shift_id', 'counterparty_shift_id',
        'reason', 'status', 'approval_id', 'requested_by_user_id', 'decided_at', 'decision_note', 'applied_at',
    ];

    protected function casts(): array
    {
        return ['swap_date' => 'date', 'decided_at' => 'datetime', 'applied_at' => 'datetime'];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requester_employee_id');
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'counterparty_employee_id');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::PENDING;
    }
}
