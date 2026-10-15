<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preferences' => ['required', 'array'],
            'preferences.*' => ['boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $allowed = config('notifications.events', []);
            $submitted = array_keys($this->input('preferences', []));
            $unknown = array_values(array_diff($submitted, $allowed));

            if ($unknown !== []) {
                $validator->errors()->add(
                    'preferences',
                    'Unknown notification event(s): '.implode(', ', $unknown),
                );
            }
        });
    }
}
