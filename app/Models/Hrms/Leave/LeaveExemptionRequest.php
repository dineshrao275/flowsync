<?php

namespace App\Models\Hrms\Leave;

use App\Enums\Hrms\LeaveRequestStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Shared\Approval;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Leave/HRMS — a statutory leave-exemption ask.
 *
 * Reuses the request status vocabulary (an exemption is decided like a
 * request, with `expired` for asks the fiscal year outran) but stands apart
 * from `leave_requests`: exemptions consume no balance and post no ledger
 * rows. P6.4 wires the jurisdiction gate; the row is deliberately
 * unopinionated until then.
 */
class LeaveExemptionRequest extends Model
{
    protected $table = 'leave_exemption_requests';

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'from_date',
        'to_date',
        'days',
        'reason',
        'status',
        'approval_id',
        'fiscal_year',
        'decided_at',
        'decided_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'leave_type_id' => 'integer',
            'from_date' => 'date',
            'to_date' => 'date',
            'days' => 'decimal:2',
            'status' => LeaveRequestStatus::class,
            'approval_id' => 'integer',
            'fiscal_year' => 'integer',
            'decided_at' => 'datetime',
            'decided_by_user_id' => 'integer',
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

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
