<?php

namespace App\Models\Hrms\Payroll;

use App\Enums\Hrms\PayrollRunStatus;
use App\Models\User;
use Database\Factories\Hrms\PayrollRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Payroll/HRMS — one pay period's run and its state.
 *
 * Drafts calculate; review refines; approval publishes intent; processing
 * disburses; paid seals payment; locked seals history; void abandons. The
 * totals snapshot the run so the review grid never recomputes — what was
 * reviewed is what gets paid.
 */
class PayrollRun extends Model
{
    use HasFactory;

    protected static function newFactory(): PayrollRunFactory
    {
        return PayrollRunFactory::new();
    }

    protected $table = 'payroll_runs';

    protected $fillable = [
        'period_year',
        'period_month',
        'pay_period_start',
        'pay_period_end',
        'pay_date',
        'status',
        'employee_count',
        'totals',
        'initiated_by_user_id',
        'approved_by_user_id',
        'approved_at',
        'processed_at',
        'locked_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
            'pay_period_start' => 'date',
            'pay_period_end' => 'date',
            'pay_date' => 'date',
            'status' => PayrollRunStatus::class,
            'employee_count' => 'integer',
            'totals' => 'array',
            'initiated_by_user_id' => 'integer',
            'approved_by_user_id' => 'integer',
            'approved_at' => 'datetime',
            'processed_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    /** @return HasMany<Payslip, $this> */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class, 'payroll_run_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function isOpen(): bool
    {
        return ! $this->status->isTerminal();
    }
}
