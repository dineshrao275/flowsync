<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Query for the repository picker and the branch/commit/pull-request reads (each endpoint uses the keys it needs). */
class GitRepositoryBrowseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'connection' => ['nullable', 'uuid'],
            'branch' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'in:open,closed,all'],
        ];
    }
}
