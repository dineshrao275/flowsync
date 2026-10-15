<?php

namespace App\Policies\Hrms\Lifecycle;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Lifecycle\DocumentRequest;
use App\Models\User;

/**
 * Lifecycle/HRMS — who may do what to a document ask.
 *
 * A single policy for both case types because a request is the same thing
 * wherever it is raised from. Reading follows the employee-document shape
 * (self or directory reader); changing the lifecycle needs the documents
 * manage permission — the ask belongs to HR’s workflow, and the employee’s
 * only move is submitting their file.
 */
class DocumentRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canView($user) || $this->hasEmployee($user);
    }

    public function view(User $user, DocumentRequest $request): bool
    {
        return $this->isSelf($user, $request->employee) || $this->canView($user);
    }

    /**
     * Raise an ask: HR’s job. The employee’s move is submitting, not asking.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('hrms.documents.manage');
    }

    /**
     * Attach the file: the person it was asked of, or HR filing on their
     * behalf. The service re-checks that the file is actually theirs.
     */
    public function submit(User $user, DocumentRequest $request): bool
    {
        return $this->isSelf($user, $request->employee)
            || $user->hasPermission('hrms.documents.manage');
    }

    public function review(User $user, DocumentRequest $request): bool
    {
        return $user->hasPermission('hrms.documents.manage');
    }

    private function canView(User $user): bool
    {
        return $user->hasPermission('hrms.documents.view')
            || $user->hasPermission('hrms.documents.manage');
    }

    private function hasEmployee(User $user): bool
    {
        return Employee::where('user_id', $user->id)->exists();
    }

    private function isSelf(User $user, ?Employee $employee): bool
    {
        return $employee !== null
            && $employee->user_id !== null
            && (int) $employee->user_id === (int) $user->id;
    }
}
