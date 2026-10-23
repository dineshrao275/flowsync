<?php

namespace App\Http\Requests\Hrms\Lifecycle;

use Illuminate\Foundation\Http\FormRequest;

class ResignationApproveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['note' => ['nullable', 'string', 'max:500']];
    }
}
