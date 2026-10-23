<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClientLogStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'level' => 'sometimes|string|in:debug,info,warn,warning,error',
            'message' => 'required|string|max:2000',
            'context' => 'sometimes|array',
            'url' => 'sometimes|nullable|string|max:1000',
        ];
    }
}
