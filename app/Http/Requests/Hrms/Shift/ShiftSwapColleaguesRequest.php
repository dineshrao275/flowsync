<?php

declare(strict_types=1);

namespace App\Http\Requests\Hrms\Shift;

use Illuminate\Foundation\Http\FormRequest;

/** Shift/HRMS — searching the names-only colleague list. Shape only; the service scopes who is listed. */
class ShiftSwapColleaguesRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
