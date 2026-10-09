<?php

namespace App\Http\Requests\Hrms\Shift;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shift/HRMS — creating or editing a rotation template. `cycle` is a list of
 * 2–28 slots; each is a shift id or null (a day off).
 */
class RotationRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $create = $this->isMethod('POST');

        return [
            'name' => [Rule::requiredIf($create), 'string', 'max:255'],
            'code' => [
                Rule::requiredIf($create), 'string', 'max:64', 'regex:/^[a-z0-9_-]+$/',
                Rule::unique('attendance_rotations', 'code')->ignore($this->route('rotation')),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'cycle' => [Rule::requiredIf($create), 'array', 'min:2', 'max:28'],
            'cycle.*' => ['nullable', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
