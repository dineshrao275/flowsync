<?php

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
        return ['alt' => ['nullable', 'string', 'max:255']];
    }
}
