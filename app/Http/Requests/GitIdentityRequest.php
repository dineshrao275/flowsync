<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Map a GitHub login to a tenant user. */
class GitIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            // GitHub logins: alphanumerics and single hyphens, max 39; a leading @ is tolerated.
            'login' => ['required', 'string', 'max:40', 'regex:/^@?[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})$/'],
        ];
    }
}
