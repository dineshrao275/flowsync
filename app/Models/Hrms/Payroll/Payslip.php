<?php

namespace App\Models\Hrms\Payroll;

use App\Enums\Hrms\PayslipStatus;
use App\Models\Hrms\Employee\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Payroll/HRMS — one employee's computed pay for a run.
 *
 * A snapshot, not a view: earnings, deductions, day counts and totals are
 * written at calculation time and never recomputed on read, so a later
 * rule change cannot rewrite a locked payslip. Adjustments ride along as
 * rows, applied into the totals at write time.
 */
class Payslip extends Model
{
    protected $table = 'payslips';

    protected $fillable = [
        'payroll_run_id',
        'employee_id',
        'employee_salary_structure_id',
        'earnings',
        'deductions',
        'employer_contributions',
        'statutory',
        'gross_pay',
        'total_deductions',
        'net_pay',
        'working_days',
        'paid_days',
        'lop_days',
        'ot_minutes',
        'absent_days',
        'leave_days',
        'status',
        'published_at',
        'locked_at',
    ];

    protected function casts(): array
    {
        return [
            'payroll_run_id' => 'integer',
            'employee_id' => 'integer',
            'employee_salary_structure_id' => 'integer',
            'earnings' => 'array',
            'deductions' => 'array',
            'employer_contributions' => 'array',
            'statutory' => 'array',
            'gross_pay' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'net_pay' => 'decimal:2',
            'working_days' => 'decimal:2',
            'paid_days' => 'decimal:2',
            'lop_days' => 'decimal:2',
            'ot_minutes' => 'integer',
            'absent_days' => 'integer',
            'leave_days' => 'array',
            'status' => PayslipStatus::class,
            'published_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeSalaryStructure::class, 'employee_salary_structure_id');
    }

    /** @return HasMany<PayslipAdjustment, $this> */
    public function adjustments(): HasMany
    {
        return $this->hasMany(PayslipAdjustment::class, 'payslip_id');
    }
}
