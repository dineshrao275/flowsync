<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Validation\Validator;

/**
 * Employee/HRMS — editing an employment record.
 *
 * Two families of field are absent on purpose, and both absences are enforced
 * rather than merely unvalidated, so the API cannot be used to reach around a
 * rule that lives in the service:
 *
 *  - the identity of the record (`employee_code`, `user_id`, `status`,
 *    `manager_id`) has its own endpoint, and each of those writes an audit or
 *    history row that a plain profile edit would bypass;
 *  - the login's own fields (`email`, `password`, `roles`) belong to the user
 *    administration surface, which is a different permission and a different
 *    audit trail.
 */
class EmployeeUpdateRequest extends EmployeeRequestBase
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = $this->profileRules(false);

        unset($rules['status'], $rules['manager_id']);

        return $rules;
    }

    /**
     * Reject the fields this endpoint does not own, by name.
     *
     * Silently dropping them would be worse: a client that sends `status` and
     * gets a 200 with no change has no way to learn it was ignored, and someone
     * will read that as a successful offboarding.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $notEditable = [
                'employee_code' => 'use the employee record\'s own code, which is allocated by the server',
                'user_id' => 'the linked login is managed from user administration',
                'email' => 'the work email belongs to the profile; the login address is managed from user administration',
                'password' => 'the password is managed from user administration',
                'roles' => 'roles are managed from user administration',
                'status' => 'use the status endpoint, which records the change in the status history',
                'manager_id' => 'use the manager endpoint, which checks for reporting cycles',
            ];

            foreach ($notEditable as $field => $why) {
                if ($this->has($field)) {
                    $validator->errors()->add($field, ucfirst($field).' is not editable here: '.$why.'.');
                }
            }
        });
    }
}
