<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Asset/HRMS — handing an asset over and taking it back.
 *
 * The asset travels in the URL (nested-route rule); the employee and the
 * condition travel here. `AssignRequest` requires its person, `ReturnRequest`
 * its condition — a handover without either is not a handover.
 */
class AssetAssignRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'condition_out' => ['sometimes', Rule::in(['new', 'good', 'fair', 'poor', 'damaged'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_id.exists' => 'That employee does not exist.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
