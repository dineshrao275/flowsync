<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SupportAccessStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mode' => ['sometimes', 'in:read_only,write'],
            'hours' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('tenancy.support_access.max_hours', 72)],
            'session_minutes' => ['sometimes', 'integer', 'min:5', 'max:'.(int) config('tenancy.support_access.max_session_minutes', 120)],
            'note' => ['required', 'string', 'min:8', 'max:500'],
            'support_ticket_id' => ['nullable', 'integer'],
        ];
    }
}
