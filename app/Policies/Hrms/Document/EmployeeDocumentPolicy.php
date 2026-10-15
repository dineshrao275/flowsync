<?php

namespace App\Policies\Hrms\Document;

use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use App\Services\Hrms\HrmsScope;

/**
 * Document/HRMS — who may do what to an employee document.
 *
 * Three different questions, not one role check: `view` answers whether the
 * caller may see this row — self-service included (own is always inside a
 * caller's scope), other people's rows when their `hrms.documents.view`
 * scope reaches them (assigned or all) — because “my documents” is the whole
 * basis of the employee surface; `viewSensitive` answers whether a
 * confidential row may be opened at all; and `verify`/`reject`/`delete` answer
 * whether the caller may change the lifecycle. A confidential row needs both
 * answers, not one — being the person in the file does not grant the extra
 * permission, and holding the extra permission does not grant someone else’s
 * non-confidential file.
 */
class EmployeeDocumentPolicy
{
    /**
     * Anyone who may reach the document surface at all: a scope reader, or
     * a person with their own employment record to read.
     */
    public function viewAny(User $user): bool
    {
        return HrmsScope::canRead($user, 'hrms.documents') || $this->hasEmployee($user);
    }

    /**
     * Read one row. Confidential additionally needs the sensitive permission:
     * the file it points at is the thing D2.8 protects, and the metadata
     * around it (title, original name) can name the condition or the account.
     */
    public function view(User $user, EmployeeDocument $document): bool
    {
        if ($document->confidential && ! $this->viewSensitive($user, $document)) {
            return false;
        }

        return HrmsScope::coversEmployee($user, 'hrms.documents', $document->employee);
    }

    /**
     * Open a confidential row. Manage implies it, mirroring
     * `EmployeePolicy::viewSensitive`: someone trusted to change the lifecycle
     * is trusted to see what they are changing.
     */
    public function viewSensitive(User $user, EmployeeDocument $document): bool
    {
        return $user->hasPermission('hrms.documents.view_sensitive')
            || $user->hasPermission('hrms.documents.manage');
    }

    /**
     * File a document for an employment record: the person it belongs to, or
     * someone trusted with the lifecycle. A directory reader may not file into
     * someone else’s record.
     */
    public function upload(User $user, Employee $employee): bool
    {
        return $this->isSelfEmployee($user, $employee)
            || $user->hasPermission('hrms.documents.manage');
    }

    public function verify(User $user, EmployeeDocument $document): bool
    {
        return $user->hasPermission('hrms.documents.manage');
    }

    public function reject(User $user, EmployeeDocument $document): bool
    {
        return $user->hasPermission('hrms.documents.manage');
    }

    public function delete(User $user, EmployeeDocument $document): bool
    {
        return $user->hasPermission('hrms.documents.manage');
    }

    private function hasEmployee(User $user): bool
    {
        return Employee::where('user_id', $user->id)->exists();
    }

    /**
     * Whether this employment record is the caller’s own.
     *
     * `user_id` is the link, not the email — the same rule as
     * `EmployeePolicy::isSelf`, because a record with no login is a real
     * person who cannot see themselves.
     */
    private function isSelfEmployee(User $user, ?Employee $employee): bool
    {
        return $employee !== null
            && $employee->user_id !== null
            && (int) $employee->user_id === (int) $user->id;
    }
}
