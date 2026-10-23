<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TwoFactorUpdatePlatformPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['require_super_admins' => ['required', 'boolean']];
    }
}
