<?php

namespace App\Http\Requests\Hrms\Org;

use App\Services\Hrms\Org\DepartmentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Org/HRMS — creating or editing a department.
 *
 * One request for both verbs, because the difference between them is a single
 * line (`name`) and a `sometimes` ahead of a `required` is how that line gets
 * skipped: `sometimes` means "ignore the rest of this field's rules when the
 * key is absent", so a conditional requirement behind it never fires.
 *
 * `slug` is absent on purpose. It is server-allocated with an auto-suffix, and
 * accepting a client's would let a rename collide with a payslip or a letter
 * template that already points at the old one.
 *
 * The *active* head rule is deliberately not duplicated here. This request
 * answers "is this a well-formed id"; whether the person behind it still works
 * here is {@see DepartmentService}'s call, because a
 * second copy of that rule is a second thing to forget to update — and the
 * failure is a departed employee displayed as a department's authority.
 */
class DepartmentRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                Rule::requiredIf(fn (): bool => $this->isMethod('POST')),
                'string',
                'max:255',
            ],
            'code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],

            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
            'head_employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parent_id.exists' => 'The parent department does not exist.',
            'head_employee_id.exists' => 'The department head does not exist.',
        ];
    }

    public function authorize(): bool
    {
        // The controller authorizes against the policy; this is only the
        // framework's pre-check, and the real answer needs the route's model.
        return true;
    }
}
