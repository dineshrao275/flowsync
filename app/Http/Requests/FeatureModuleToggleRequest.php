<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FeatureModuleToggleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'module' => ['required', 'string', Rule::in(config('subscriptions.modules', []))],
            'enabled' => ['required', 'boolean'],
        ];
    }
}
