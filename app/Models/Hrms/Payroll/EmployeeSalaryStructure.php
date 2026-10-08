<?php

namespace App\Models\Hrms\Payroll;

use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Payroll/HRMS — what one person earns, from a date.
 *
 * One row per (employee, effective_from): a raise closes the current row
 * (stamping `effective_to`) and opens the next, so the full history reads
 * as a chain and the live row is simply the open one. Money stays decimal
 * end to end — the engine writes these strings, never floats.
 */
class EmployeeSalaryStructure extends Model
{
    protected $table = 'employee_salary_structures';

    protected $fillable = [
        'employee_id',
        'structure_id',
        'ctc_annual',
        'monthly_ctc',
        'gross_monthly',
        'effective_from',
        'effective_to',
        'reason',
        'is_current',
        'approved_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'structure_id' => 'integer',
            'ctc_annual' => 'decimal:2',
            'monthly_ctc' => 'decimal:2',
            'gross_monthly' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_current' => 'boolean',
            'approved_by_user_id' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'structure_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
