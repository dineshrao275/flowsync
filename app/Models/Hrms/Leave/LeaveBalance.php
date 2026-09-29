<?php

namespace App\Models\Hrms\Leave;

use App\Models\Hrms\Employee\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Leave/HRMS — one employee's projected balance for a type and leave year.
 *
 * A materialised projection, not the truth: `LeaveService::rebuildBalance()`
 * re-sums the ledger into this row, so every column here must equal its
 * ledger family's sum (modulo the `max_balance` cap on `balance` itself).
 * Never written by hand — only the engine writes here, which is what keeps
 * the projection honest.
 */
class LeaveBalance extends Model
{
    protected $table = 'leave_balances';

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'year',
        'opening',
        'accrued',
        'availed',
        'encashed',
        'lapsed',
        'carried_forward',
        'adjusted',
        'balance',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'leave_type_id' => 'integer',
            'year' => 'integer',
            'opening' => 'decimal:2',
            'accrued' => 'decimal:2',
            'availed' => 'decimal:2',
            'encashed' => 'decimal:2',
            'lapsed' => 'decimal:2',
            'carried_forward' => 'decimal:2',
            'adjusted' => 'decimal:2',
            'balance' => 'decimal:2',
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
}
