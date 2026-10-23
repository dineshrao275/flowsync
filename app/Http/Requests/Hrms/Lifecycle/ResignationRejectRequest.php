<?php

namespace App\Http\Requests\Hrms\Lifecycle;

use Illuminate\Foundation\Http\FormRequest;

class ResignationRejectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['note' => ['required', 'string', 'max:500']];
    }
}
