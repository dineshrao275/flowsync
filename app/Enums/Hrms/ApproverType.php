<?php

namespace App\Enums\Hrms;

/**
 * How an approval step decides who may act on it (D2.4).
 */
enum ApproverType: string
{
    /** Anyone holding a given tenant role. */
    case Role = 'role';

    /** One specific user. */
    case User = 'user';

    /** The requester's reporting manager, resolved from the employee record. */
    case Manager = 'manager';

    /** The head of the requester's department, falling back to their manager. */
    case DepartmentHead = 'department_head';

    /**
     * The employee/user column this type resolves through, if any. `role` and
     * `user` are resolved eagerly at request time; `manager` and
     * `department_head` are resolved against the subject employee when the flow
     * is created.
     */
    public function resolvesLazily(): bool
    {
        return in_array($this, [self::Manager, self::DepartmentHead], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Role => 'Role',
            self::User => 'User',
            self::Manager => 'Reporting manager',
            self::DepartmentHead => 'Department head',
        };
    }
}
