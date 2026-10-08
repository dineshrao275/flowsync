<?php

namespace App\Models\Hrms\Payroll;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Payroll/HRMS — one extra line on a payslip.
 *
 * Bonuses, recoveries, reimbursements: anything outside the structure's
 * heads, with its source recorded where one exists (an expense claim, an
 * encashed leave row) so the line is traceable, not just payable. Earnings
 * add to net, deductions take from it; the engine folds both into the
 * snapshot totals at write time.
 */
class PayslipAdjustment extends Model
{
    protected $table = 'payslip_adjustments';

    public $timestamps = false;

    protected $fillable = [
        'payslip_id',
        'component_id',
        'kind',
        'label',
        'amount',
        'reference_type',
        'reference_id',
        'note',
        'actor_user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payslip_id' => 'integer',
            'component_id' => 'integer',
            'amount' => 'decimal:2',
            'reference_id' => 'integer',
            'actor_user_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class, 'payslip_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'component_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
