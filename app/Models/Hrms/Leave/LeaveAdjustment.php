<?php

namespace App\Models\Hrms\Leave;

use App\Enums\Hrms\LeaveAdjustmentKind;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Leave/HRMS — one ledger entry, the source of truth balances are rebuilt from.
 *
 * Append-only: rows are never updated, only offset (a cancelled approval
 * writes an `adjustment` row, it does not delete the `availed` one), so the
 * ledger answers "what happened" as well as "what is". `quantity` is signed
 * — credits positive, debits negative — because the sign *is* the direction
 * and an unsigned column would need a second field saying it twice.
 */
class LeaveAdjustment extends Model
{
    protected $table = 'leave_adjustments';

    public $timestamps = false;

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'year',
        'kind',
        'quantity',
        'reference_type',
        'reference_id',
        'note',
        'actor_user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'leave_type_id' => 'integer',
            'year' => 'integer',
            'kind' => LeaveAdjustmentKind::class,
            'quantity' => 'decimal:2',
            'reference_id' => 'integer',
            'actor_user_id' => 'integer',
            'created_at' => 'datetime',
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

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
