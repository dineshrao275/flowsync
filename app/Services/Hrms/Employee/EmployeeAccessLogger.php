<?php

namespace App\Services\Hrms\Employee;

use App\Enums\Hrms\DataAccessAction;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use App\Services\HrmsAuditLogger;

/**
 * Employee/HRMS — the data access ledger for employment records.
 *
 * Its own class because *when a read counts as an access to sensitive data* is
 * a rule, and a rule that lives as two `if` statements in a controller is a rule
 * that every future read endpoint has to remember to duplicate. Two conditions
 * gate the row, and both are easy to get wrong in isolation:
 *
 * - **Did the reader actually see the personal fields?** A reader without
 *   `hrms.documents.view_sensitive` got a masked payload, so logging them would
 *   be a false record — and a ledger full of false records is worse than an
 *   empty one, because a real read then looks like the anomaly.
 * - **Was anything personal actually populated?** Reading a record with no
 *   personal data on it exposes nothing, and a row claiming otherwise is noise
 *   that buries the rows that matter.
 */
class EmployeeAccessLogger
{
    public function __construct(
        private readonly HrmsAuditLogger $audit,
        private readonly SensitiveFieldRedactor $redactor,
    ) {}

    /**
     * Record a read of one employee's profile.
     *
     * No-ops unless the reader was shown the personal fields.
     */
    public function recordView(Employee $employee, ?User $actor, ?string $ipAddress, bool $sawSensitive): void
    {
        if (! $sawSensitive) {
            return;
        }

        $fields = $this->redactor->exposedColumns($employee);

        if ($fields === []) {
            return;
        }

        $this->audit->accessed(
            (new Employee)->getMorphClass(),
            $employee->id,
            DataAccessAction::View,
            $fields,
            $actor,
            $ipAddress,
        );
    }
}
