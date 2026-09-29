<?php

namespace App\Services\Hrms\Leave;

use App\Models\Hrms\Leave\LeaveBalance;
use App\Models\Hrms\Leave\LeavePolicy;
use App\Models\Hrms\Leave\LeaveType;

/**
 * Leave/HRMS — what catalogue rows look like over HTTP.
 *
 * One shape per resource, defined once (D2.16.4). Balance, request and
 * exemption shapes join this presenter with their own tasks.
 */
class LeavePresenter
{
    /**
     * @return array<string, mixed>
     */
    public function type(LeaveType $type): array
    {
        return [
            'id' => $type->id,
            'name' => $type->name,
            'slug' => $type->slug,
            'code' => $type->code,
            'is_paid' => $type->is_paid,
            'accrual_method' => $type->accrual_method->value,
            'accrual_method_label' => $type->accrual_method->label(),
            'accrual_rate' => (float) $type->accrual_rate,
            'max_balance' => $type->max_balance === null ? null : (float) $type->max_balance,
            'carry_forward' => $type->carry_forward,
            'carry_forward_cap' => $type->carry_forward_cap === null ? null : (float) $type->carry_forward_cap,
            'encashable' => $type->encashable,
            'requires_document_after_days' => $type->requires_document_after_days,
            'min_days_per_request' => $type->min_days_per_request === null ? null : (float) $type->min_days_per_request,
            'max_days_per_year' => $type->max_days_per_year === null ? null : (float) $type->max_days_per_year,
            'allow_half_day' => $type->allow_half_day,
            'allow_negative_balance' => $type->allow_negative_balance,
            'color' => $type->color,
            'position' => $type->position,
            'is_system' => $type->is_system,
            'is_active' => $type->is_active,
            'policies_count' => (int) ($type->policies_count ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function policy(LeavePolicy $policy): array
    {
        return [
            'id' => $policy->id,
            'name' => $policy->name,
            'slug' => $policy->slug,
            'accrual_period' => $policy->accrual_period->value,
            'accrual_period_label' => $policy->accrual_period->label(),
            'start_month' => $policy->start_month,
            'carry_forward_day' => $policy->carry_forward_day,
            'max_carry_forward' => $policy->max_carry_forward === null ? null : (float) $policy->max_carry_forward,
            'negative_balance_allowed' => $policy->negative_balance_allowed,
            'max_negative_days' => $policy->max_negative_days === null ? null : (float) $policy->max_negative_days,
            'description' => $policy->description,
            'is_default' => $policy->is_default,
            'is_active' => $policy->is_active,
            'types_count' => (int) ($policy->types_count ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function balance(LeaveBalance $balance): array
    {
        return [
            'employee_id' => $balance->employee_id,
            'leave_type_id' => $balance->leave_type_id,
            'year' => $balance->year,
            'type' => $balance->type === null ? null : [
                'id' => $balance->type->id,
                'name' => $balance->type->name,
                'code' => $balance->type->code,
                'is_paid' => $balance->type->is_paid,
            ],
            'opening' => (float) $balance->opening,
            'accrued' => (float) $balance->accrued,
            'availed' => (float) $balance->availed,
            'encashed' => (float) $balance->encashed,
            'lapsed' => (float) $balance->lapsed,
            'carried_forward' => (float) $balance->carried_forward,
            'adjusted' => (float) $balance->adjusted,
            'balance' => (float) $balance->balance,
        ];
    }
}
