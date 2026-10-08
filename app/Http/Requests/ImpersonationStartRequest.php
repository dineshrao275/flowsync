<?php

namespace App\Http\Requests;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImpersonationStartRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer'],
            'tenant_id' => [
                'nullable',
                'integer',
                Rule::exists(Tenant::class, 'id'),
            ],
        ];
    }
}
