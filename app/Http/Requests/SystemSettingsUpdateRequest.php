<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SystemSettingsUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'app_name' => ['nullable', 'string', 'max:255'],
            'public_registration' => ['nullable', 'boolean'],
            'default_plan_id' => ['nullable', 'integer', 'min:1'],
            'maintenance_mode' => ['nullable', 'boolean'],
        ];
    }
}
