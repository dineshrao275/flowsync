<?php

namespace App\Http\Requests\Hrms;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Employee/HRMS — creating an employment record.
 *
 * Carries the one thing a create has that an update does not: the inline login.
 * Either the caller points at an existing `users` row, or supplies the fields to
 * make one — never both, because "link this account" and "create an account"
 * are different intentions and silently honouring one of them is how a hire ends
 * up attached to somebody else's login.
 */
class EmployeeStoreRequest extends EmployeeRequestBase
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return array_merge($this->profileRules(true), [
            'user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],

            'email' => [
                'sometimes',
                'nullable',
                'email',
                'max:255',
                // Normalised to lowercase in prepareForValidation(), because
                // this checks the tenant's own users table and login then
                // matches `users.email` case-sensitively.
                Rule::unique('users', 'email'),
            ],
            // The conditional requirement comes FIRST, and there is no
            // `sometimes` here. `sometimes` means "skip every remaining rule for
            // this field when the key is absent", so `sometimes` ahead of
            // `requiredIf` skips the very rule that was supposed to fire — and
            // the create succeeds with no password at all.
            'password' => [
                Rule::requiredIf(fn () => $this->filled('email') && ! $this->filled('user_id')),
                'nullable',
                'string',
                'min:8',
            ],
            'roles' => ['sometimes', 'nullable', 'array', 'max:5'],
            'roles.*' => ['string', 'exists:roles,slug'],
        ]);
    }

    /**
     * Lower-case the work email before anything checks uniqueness.
     *
     * `UserController::store` and `EmployeeUserProvisioner` both do the same
     * thing for the same reason: a stored `New.Hire@Acme.Test` is only reachable
     * by typing that exact casing, because the login path lower-cases to resolve
     * the tenant and then compares case-sensitively.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('email') && is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    /**
     * Refuse the ambiguous "link *and* create" payload.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->filled('user_id') && ($this->filled('email') || $this->filled('password'))) {
                $validator->errors()->add(
                    'user_id',
                    'Provide either the user_id of an existing login or the fields to create one, not both.',
                );
            }
        });
    }
}
