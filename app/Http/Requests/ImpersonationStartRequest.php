<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImpersonationStartRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer'],
            'tenant_id' => ['nullable', 'integer'],
        ];
    }
}
