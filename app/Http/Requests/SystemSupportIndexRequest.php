<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\SupportTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SystemSupportIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in([...SupportTicket::STATUSES, 'open_all'])],
            'priority' => ['nullable', Rule::in(SupportTicket::PRIORITIES)],
            'tenant_id' => ['nullable', 'integer'],
            'assigned' => ['nullable', Rule::in(['me', 'none'])],
            'q' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
