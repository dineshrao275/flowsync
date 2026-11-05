<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ErrorLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SystemErrorIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(ErrorLog::STATUSES)],
            'severity' => ['nullable', Rule::in(ErrorLog::SEVERITIES)],
            'source' => ['nullable', Rule::in(ErrorLog::SOURCES)],
            'module' => ['nullable', 'string', 'max:64'],
            'tenant_id' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'sort' => ['nullable', Rule::in(['last_seen', 'first_seen', 'occurrences'])],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
