<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ContactStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'source' => ['nullable', 'string', 'in:contact,demo'],
            'plan_interest' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
        ];
    }
}
