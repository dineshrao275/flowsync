<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Filters for the platform Git connection and delivery lists. */
class GitPlatformListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'in:active,suspended,revoked,received,processed,ignored,failed'],
            'q' => ['nullable', 'string', 'max:100'],
            'connection' => ['nullable', 'uuid'],
        ];
    }
}
