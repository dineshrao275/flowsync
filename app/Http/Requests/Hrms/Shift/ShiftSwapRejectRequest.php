<?php

declare(strict_types=1);

namespace App\Http\Requests\Hrms\Shift;

use Illuminate\Foundation\Http\FormRequest;

class ShiftSwapRejectRequest extends FormRequest
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
