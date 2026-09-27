<?php

namespace App\Services\Hrms\Employee;

use App\Models\Hrms\Employee\Employee;

/**
 * Employee/HRMS — the response shape for one employment record.
 *
 * Its own class, and not a `present()` on the model, for two reasons. The model
 * is the domain and must not know about HTTP or about who is asking; and the
 * answer to "what may this caller see" is a *different payload*, not the same
 * payload with blanks in it.
 *
 * Both payloads have the **same keys**. A reader without
 * `hrms.documents.view_sensitive` gets every personal field present and masked
 * (see {@see SensitiveFieldRedactor}), not absent: a fixed shape means one
 * client layout instead of two, and a key that vanishes forces every call site
 * to ask whether the person has no phone or the caller simply may not see it.
 * `restricted: true` is the flag that separates "masked" from "not set".
 */
class EmployeePresenter
{
    public function __construct(private readonly SensitiveFieldRedactor $redactor) {}

    /**
     * The public record: enough for a directory row or a roster.
     *
     * @return array<string, mixed>
     */
    public function present(Employee $employee, bool $includeSensitive = false): array
    {
        $record = $this->publicFields($employee);

        if (! $includeSensitive) {
            return array_merge($record, $this->redactor->masked($employee));
        }

        return array_merge($record, $this->sensitive($employee));
    }

    /**
     * The fields every reader gets: enough for a directory row or a roster.
     *
     * @return array<string, mixed>
     */
    private function publicFields(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'employee_code' => $employee->employee_code,
            'name' => $employee->name,
            'preferred_name' => $employee->preferred_name,
            'display_name' => $employee->displayName(),
            'designation' => $employee->designation,
            'work_mode' => $employee->work_mode->value,
            'work_mode_label' => $employee->work_mode->label(),
            'status' => $employee->status->value,
            'status_label' => $employee->status->label(),
            'status_color' => $employee->status->color(),
            'is_employed' => $employee->status->isEmployed(),
            'employment_type' => $this->employmentType($employee),
            'manager' => $this->manager($employee),
            'user' => $this->user($employee),
            // Left null here: only the caller knows the central tenant id, so
            // only the caller can sign a photo URL.
            'photo_url' => null,
            'joining_date' => $employee->joining_date?->toDateString(),
            'confirmation_date' => $employee->confirmation_date?->toDateString(),
            'probation_end_date' => $employee->probation_end_date?->toDateString(),
            'exit_date' => $employee->exit_date?->toDateString(),
            'exited_reason' => $employee->exited_reason,
            'tenure_years' => $employee->tenureOn(),
            'created_at' => $employee->created_at?->toIso8601String(),
            'updated_at' => $employee->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The status ledger, newest first, for the profile drawer.
     *
     * @return list<array<string, mixed>>
     */
    public function presentHistory(Employee $employee): array
    {
        return $employee->statusHistory
            ->map(fn ($entry) => [
                'id' => $entry->id,
                'from_status' => $entry->from_status?->value,
                // Both labels travel with the row: a ledger that says only
                // "Probation" has lost the transition, which is the part
                // someone reads when they ask who moved a person and from where.
                'from_status_label' => $entry->from_status?->label(),
                'to_status' => $entry->to_status->value,
                'to_status_label' => $entry->to_status->label(),
                'effective_date' => $entry->effective_date?->toDateString(),
                'reason' => $entry->reason,
                'note' => $entry->note,
                'actor' => $entry->actor ? [
                    'id' => $entry->actor->id,
                    'name' => $entry->actor->name,
                ] : null,
                'created_at' => $entry->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * The personal fields. Only ever merged in behind a policy check.
     *
     * @return array<string, mixed>
     */
    private function sensitive(Employee $employee): array
    {
        return [
            'restricted' => false,
            'personal_email' => $employee->personal_email,
            'phone' => $employee->phone,
            'date_of_birth' => $employee->date_of_birth?->toDateString(),
            'gender' => $employee->gender,
            'marital_status' => $employee->marital_status,
            'nationality' => $employee->nationality,
            'address' => [
                'line1' => $employee->address_line1,
                'line2' => $employee->address_line2,
                'city' => $employee->city,
                'state' => $employee->state,
                'postal_code' => $employee->postal_code,
                'country' => $employee->country,
            ],
            'emergency_contact' => [
                'name' => $employee->emergency_contact_name,
                'phone' => $employee->emergency_contact_phone,
                'relation' => $employee->emergency_contact_relation,
            ],
            'notes' => $employee->notes,
        ];
    }

    /**
     * @return array{id: int, name: string, code: string|null}|null
     */
    private function employmentType(Employee $employee): ?array
    {
        if ($employee->employmentType === null) {
            return null;
        }

        return [
            'id' => $employee->employmentType->id,
            'name' => $employee->employmentType->name,
            'code' => $employee->employmentType->code,
        ];
    }

    /**
     * @return array{id: int, employee_code: string, name: string}|null
     */
    private function manager(Employee $employee): ?array
    {
        if ($employee->manager === null) {
            return null;
        }

        return [
            'id' => $employee->manager->id,
            'employee_code' => $employee->manager->employee_code,
            'name' => $employee->manager->displayName(),
        ];
    }

    /**
     * @return array{id: int, name: string, email: string}|null
     */
    private function user(Employee $employee): ?array
    {
        if ($employee->user === null) {
            return null;
        }

        return [
            'id' => $employee->user->id,
            'name' => $employee->user->name,
            'email' => $employee->user->email,
        ];
    }
}
