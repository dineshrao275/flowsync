<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** GitHub's post-installation redirect query. Public: the encrypted state inside it is the credential. */
class GitSetupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'state' => ['required', 'string', 'max:2000'],
            'installation_id' => ['required', 'integer', 'min:1'],
            'code' => ['nullable', 'string', 'max:255'],
        ];
    }
}
