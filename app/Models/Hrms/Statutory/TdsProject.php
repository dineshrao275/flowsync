<?php

namespace App\Models\Hrms\Statutory;

use App\Models\Hrms\Employee\Employee;
use App\Support\Hrms\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Statutory/HRMS — one quarter's TDS projection for one employee.
 *
 * A projection, not a payment: `tax_liability` is what the slabs say the
 * quarter owes, `tds_deducted` what the payslips priced, `tds_surrendered`
 * what was deposited against a challan. Re-projection refreshes the
 * computed columns and never touches the surrender columns — a deposit is
 * a fact, not an estimate.
 */
class TdsProject extends Model
{
    protected $table = 'tds_projects';

    protected $fillable = [
        'employee_id',
        'fiscal_year',
        'quarter',
        'declared_income',
        'exempt_income',
        'projected_income',
        'tax_liability',
        'tds_deducted',
        'tds_surrendered',
        'challan_ref',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'fiscal_year' => 'integer',
            'quarter' => 'integer',
            'declared_income' => 'decimal:2',
            'exempt_income' => 'decimal:2',
            'projected_income' => 'decimal:2',
            'tax_liability' => 'decimal:2',
            'tds_deducted' => 'decimal:2',
            'tds_surrendered' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * What the quarter still owes after payslip deductions and deposits.
     */
    public function shortfall(): string
    {
        $liability = Money::fromDecimal((string) $this->tax_liability);
        $covered = Money::fromDecimal((string) $this->tds_deducted)
            ->add(Money::fromDecimal((string) $this->tds_surrendered));

        $gap = $liability->sub($covered);

        return $gap->isNegative() ? '0.00' : $gap->toDecimal();
    }
}
