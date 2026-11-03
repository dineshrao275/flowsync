<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Link (POST) or re-configure (PUT) a project repository. Authorization is the route + controller policy. */
class ProjectRepositoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'repository_uuid' => $creating ? ['required', 'uuid'] : ['prohibited'],
            'sync_enabled' => ['sometimes', 'boolean'],
            'smart_commits_enabled' => ['sometimes', 'boolean'],
            'allow_cross_project' => ['sometimes', 'boolean'],
            'default_branch' => ['sometimes', 'nullable', 'string', 'max:150'],
        ];
    }
}
