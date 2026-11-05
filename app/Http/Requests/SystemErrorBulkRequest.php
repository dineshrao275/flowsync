<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ErrorLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SystemErrorBulkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'distinct'],
            'status' => ['required', Rule::in(ErrorLog::STATUSES)],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
