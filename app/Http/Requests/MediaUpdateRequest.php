<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MediaUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // `present`: the screen always sends the field (empty clears it); a request without it would otherwise crash the update.
        return ['alt' => ['present', 'nullable', 'string', 'max:255']];
    }
}
