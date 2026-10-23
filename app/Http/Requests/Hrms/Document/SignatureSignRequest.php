<?php

namespace App\Http\Requests\Hrms\Document;

use Illuminate\Foundation\Http\FormRequest;

class SignatureSignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['typed_name' => ['required', 'string', 'max:255'], 'password' => ['required', 'string']];
    }
}
