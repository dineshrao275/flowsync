<?php

namespace App\Policies\Hrms\Employee;

use App\Models\Hrms\Employee\Employee;
use App\Models\User;

/**
 * Employee/HRMS — who may do what to an employment record.
 *
 * Split out per D2.16.2 so the HRMS policy surface has one home, and written
 * against three *different* questions rather than one role check:
 *
 *  - `view` is deliberately broader than manage: an employee reading their own
 *    record is the whole basis of self-service, and it does not require anyone
 *    else to hand them a copy.
 *  - `viewSensitive` is separate again and narrower than view, because the
 *    national id, bank details and home address are the fields that turn a
 *    directory into a target list. Someone who can read the roster should not
 *    automatically be able to read those.
 *  - `delete` is the one irreversible one, and it carries a data condition
 *    (a departed employee) on top of the permission.
 */
class EmployeePolicy
{
    /**
     * Any authenticated employee record reader.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('hrms.employees.view')
            || $user->hasPermission('hrms.employees.manage');
    }

    /**
     * Read one record: anyone with the directory permission, or the person it
     * belongs to.
     */
    public function view(User $user, Employee $employee): bool
    {
        return $this->isSelf($user, $employee) || $this->viewAny($user);
    }

    /**
     * Read the personal fields: personal contact, home address, date of birth,
     * national id and bank details, once they exist.
     */
    public function viewSensitive(User $user, Employee $employee): bool
    {
        return $user->hasPermission('hrms.documents.view_sensitive')
            || $user->hasPermission('hrms.employees.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('hrms.employees.manage');
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->hasPermission('hrms.employees.manage');
    }

    public function changeStatus(User $user, Employee $employee): bool
    {
        return $user->hasPermission('hrms.employees.manage');
    }

    public function assignManager(User $user, Employee $employee): bool
    {
        return $user->hasPermission('hrms.employees.manage');
    }

    public function terminate(User $user, Employee $employee): bool
    {
        return $user->hasPermission('hrms.employees.manage');
    }

    /**
     * Remove a record, optionally overriding the departed-employee guard.
     *
     * The guard exists because deleting a departed employee is nearly always a
     * mistaken request: they left, their payroll history and compliance records
     * still refer to them, and a hard delete quietly removes the person from
     * every report they ever appeared in. So it takes an explicit `force` — and
     * the soft delete means even a forced delete keeps the row.
     *
     * Written as `isOffboarding()` rather than the plan's literal
     * `status = exited`, because both terminal states are a departure and
     * neither is a mistake worth allowing by default.
     */
    public function delete(User $user, Employee $employee, bool $force = false): bool
    {
        if (! $user->hasPermission('hrms.employees.manage')) {
            return false;
        }

        if ($force) {
            return true;
        }

        return ! $employee->status->isOffboarding();
    }

    /**
     * Whether this record is the caller's own employment.
     *
     * `user_id` is the link, not the email: an employee record with no login is
     * a real person who cannot see themselves, and a service account with a
     * login and no employee record must not inherit the whole directory.
     */
    private function isSelf(User $user, Employee $employee): bool
    {
        return $employee->user_id !== null && (int) $employee->user_id === (int) $user->id;
    }
}
