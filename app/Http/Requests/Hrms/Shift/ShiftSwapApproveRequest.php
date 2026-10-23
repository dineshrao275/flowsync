<?php

namespace App\Http\Requests\Hrms\Shift;

use Illuminate\Foundation\Http\FormRequest;

class ShiftSwapApproveRequest extends FormRequest
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
