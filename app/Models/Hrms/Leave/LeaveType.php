<?php

namespace App\Models\Hrms\Leave;

use App\Enums\Hrms\LeaveAccrualMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Leave/HRMS — one leave kind in the tenant's catalog.
 *
 * `accrual_rate` means "days per period of `accrual_method`": 30 annual, 1
 * monthly. `max_balance` caps the stored balance (the ledger keeps the full
 * history, so the cap stays answerable); `allow_negative_balance` (unpaid
 * leave's natural state) skips the floor instead.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $code
 * @property bool $is_paid
 * @property LeaveAccrualMethod $accrual_method
 */
class LeaveType extends Model
{
    protected $table = 'leave_types';

    protected $fillable = [
        'name',
        'slug',
        'code',
        'is_paid',
        'accrual_method',
        'accrual_rate',
        'max_balance',
        'carry_forward',
        'carry_forward_cap',
        'encashable',
        'requires_document_after_days',
        'min_days_per_request',
        'max_days_per_year',
        'allow_half_day',
        'allow_negative_balance',
        'color',
        'position',
        'is_system',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_paid' => 'boolean',
            'accrual_method' => LeaveAccrualMethod::class,
            'accrual_rate' => 'decimal:3',
            'max_balance' => 'decimal:2',
            'carry_forward' => 'boolean',
            'carry_forward_cap' => 'decimal:2',
            'encashable' => 'boolean',
            'requires_document_after_days' => 'integer',
            'min_days_per_request' => 'decimal:2',
            'max_days_per_year' => 'decimal:2',
            'allow_half_day' => 'boolean',
            'allow_negative_balance' => 'boolean',
            'position' => 'integer',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsToMany<LeavePolicy, $this> */
    public function policies(): BelongsToMany
    {
        return $this->belongsToMany(LeavePolicy::class, 'leave_policy_types', 'leave_type_id', 'policy_id');
    }

    /** @return HasMany<LeaveBalance, $this> */
    public function balances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class, 'leave_type_id');
    }

    /** @return HasMany<LeaveAdjustment, $this> */
    public function adjustments(): HasMany
    {
        return $this->hasMany(LeaveAdjustment::class, 'leave_type_id');
    }

    /** @return HasMany<LeaveRequest, $this> */
    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'leave_type_id');
    }
}
