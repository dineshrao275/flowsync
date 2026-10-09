<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApiTokenStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by the `api.manage` route middleware; abilities are checked against the caller in the service
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', 'max:80'],
            'can_write' => ['sometimes', 'boolean'],
            'rate_limit' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('api.max_rate_limit')],
            'expires_at' => ['nullable', 'date', 'after:now', 'before:'.now()->addDays((int) config('api.max_expiry_days'))->toDateTimeString()],
        ];
    }
}
