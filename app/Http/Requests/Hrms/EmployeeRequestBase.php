<?php

namespace App\Http\Requests\Hrms;

use App\Enums\Hrms\EmployeeStatus;
use App\Enums\Hrms\WorkMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Employee/HRMS — the create payload.
 *
 * A shared rule set, because the profile fields are identical on create and
 * update and two copies of a 30-rule array is how the two drift: a field added
 * to the profile and not to the request is a field the API silently refuses.
 * Only the *requiredness* differs, and that is the one line each subclass owns.
 */
abstract class EmployeeRequestBase extends FormRequest
{
    /**
     * The rules shared by every employee write.
     *
     * `employee_code` is deliberately absent: it is server-allocated from
     * `EmployeeCodeGenerator`, and a client-supplied one would let a caller
     * collide with a payslip already issued in that number.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function profileRules(bool $nameRequired): array
    {
        return array_merge($this->orgRules(), [
            'name' => [$nameRequired ? 'required' : 'sometimes', 'string', 'max:255'],
            'preferred_name' => ['sometimes', 'nullable', 'string', 'max:255'],

            'personal_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
            'gender' => ['sometimes', 'nullable', 'string', 'max:32'],
            'marital_status' => ['sometimes', 'nullable', 'string', 'max:32'],
            'nationality' => ['sometimes', 'nullable', 'string', 'max:64'],

            'address_line1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'state' => ['sometimes', 'nullable', 'string', 'max:255'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:16'],
            'country' => ['sometimes', 'nullable', 'string', 'max:2'],

            'emergency_contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'emergency_contact_relation' => ['sometimes', 'nullable', 'string', 'max:64'],

            'joining_date' => ['sometimes', 'nullable', 'date'],
            // A probation cannot end before the job starts, and a confirmation
            // cannot predate it. Both are cheap to check here and very hard to
            // notice later on a payslip.
            'probation_end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:joining_date'],
            'confirmation_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:joining_date'],

            'employment_type_id' => ['sometimes', 'nullable', 'integer', 'exists:employment_types,id'],
            'designation' => ['sometimes', 'nullable', 'string', 'max:255'],
            'manager_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'work_mode' => ['sometimes', 'nullable', Rule::enum(WorkMode::class)],
            'status' => ['sometimes', 'nullable', Rule::enum(EmployeeStatus::class)],

            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
    }

    /**
     * The org references (P3.1's columns, reachable since P3.3), kept apart
     * from the profile above because they are the one part of this payload
     * another context owns: a department, a designation catalogue and a
     * location list each have their own lifecycle and their own delete guards,
     * and a rule change in any of those should not have to be read next to a
     * payslip proration field.
     *
     * `exists:` resolves on the default connection, which during a tenant
     * request is the tenant's own database — so it checks the tenant's
     * departments, not a row from a sibling tenant.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function orgRules(): array
    {
        return [
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
            'designation_id' => ['sometimes', 'nullable', 'integer', 'exists:designations,id'],
            'location_id' => ['sometimes', 'nullable', 'integer', 'exists:locations,id'],
        ];
    }
}
