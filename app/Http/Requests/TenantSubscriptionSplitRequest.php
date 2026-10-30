<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TenantSubscriptionSplitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tms_plan_id' => ['nullable', 'integer', Rule::exists('subscription_plans', 'id')->where('product', 'tms')],
            'hrms_plan_id' => ['nullable', 'integer', Rule::exists('subscription_plans', 'id')->where('product', 'hrms')],
        ];
    }
}
