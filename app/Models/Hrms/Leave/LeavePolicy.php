<?php

namespace App\Models\Hrms\Leave;

use App\Enums\Hrms\LeaveAccrualPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Leave/HRMS — accrual and calendar rules shared by a set of types.
 *
 * The tenant's leave year starts at `start_month` (April in India, January
 * by default) — every year boundary in the engine reads it, never the
 * calendar year. `is_default` marks the policy new accruals resolve periods
 * against; the single-default rule is a service concern (P6.4), not a
 * partial index (the boolean-predicate trap in 0.6).
 */
class LeavePolicy extends Model
{
    protected $table = 'leave_policies';

    protected $fillable = [
        'name',
        'slug',
        'accrual_period',
        'start_month',
        'carry_forward_day',
        'max_carry_forward',
        'negative_balance_allowed',
        'max_negative_days',
        'description',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'accrual_period' => LeaveAccrualPeriod::class,
            'start_month' => 'integer',
            'carry_forward_day' => 'integer',
            'max_carry_forward' => 'decimal:2',
            'negative_balance_allowed' => 'boolean',
            'max_negative_days' => 'decimal:2',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsToMany<LeaveType, $this> */
    public function types(): BelongsToMany
    {
        return $this->belongsToMany(LeaveType::class, 'leave_policy_types', 'policy_id', 'leave_type_id');
    }

    /** @param Builder<LeavePolicy> $query */
    public function scopeDefault(Builder $query): void
    {
        $query->where('is_default', true);
    }
}
