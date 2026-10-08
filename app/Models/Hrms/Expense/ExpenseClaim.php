<?php

namespace App\Models\Hrms\Expense;

use App\Enums\Hrms\ExpenseClaimStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Payroll\PayrollRun;
use App\Models\Hrms\Shared\Approval;
use App\Models\User;
use Database\Factories\Hrms\ExpenseClaimFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Expense/HRMS — one reimbursement claim.
 *
 * Totals are server-computed from the items, never trusted from the
 * client; the number is server-stamped (`EXP-2026-000001`) after insert.
 * Submission locks the figures, the approval chain decides, and payroll
 * reimburses approved claims into payslip adjustments by reference — this
 * model never calls payroll itself.
 */
class ExpenseClaim extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): ExpenseClaimFactory
    {
        return ExpenseClaimFactory::new();
    }

    protected $table = 'expense_claims';

    protected $fillable = [
        'employee_id',
        'claim_number',
        'claim_date',
        'period_year',
        'period_month',
        'purpose',
        'description',
        'currency',
        'total_amount',
        'approved_amount',
        'reimbursed_amount',
        'status',
        'approval_id',
        'paid_in_payroll_run_id',
        'paid_via',
        'decided_at',
        'decided_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'claim_date' => 'date',
            'period_year' => 'integer',
            'period_month' => 'integer',
            'total_amount' => 'decimal:2',
            'approved_amount' => 'decimal:2',
            'reimbursed_amount' => 'decimal:2',
            'status' => ExpenseClaimStatus::class,
            'approval_id' => 'integer',
            'paid_in_payroll_run_id' => 'integer',
            'decided_at' => 'datetime',
            'decided_by_user_id' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<ExpenseClaimItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ExpenseClaimItem::class, 'claim_id');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    public function paidInRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'paid_in_payroll_run_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
