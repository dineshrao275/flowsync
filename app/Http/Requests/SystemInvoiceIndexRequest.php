<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SystemInvoiceIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tenant_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:open,paid,void'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
