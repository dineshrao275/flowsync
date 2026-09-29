<?php

namespace App\Services\Hrms\Leave;

use App\Enums\Hrms\ApproverType;
use App\Models\Hrms\Employee\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\Hrms\Employee\ReportingLine;
use App\Services\Hrms\Shared\ValueObjects\ApproverSpec;

/**
 * Leave/HRMS — who decides an ask, in which order.
 *
 * Manager first, then the department head, then anyone holding the HR
 * manager role. Three rules keep the chain honest:
 *
 * - **The department head falls back to the manager** when the department
 *   names none, or names the requester (nobody approves their own leave).
 *   The fallback may name the step-one approver — the same human then
 *   approves twice, visibly, in two ledger rows. Collapsing the step would
 *   make the chain shape depend on who holds which chair, and a queue that
 *   shows "1 of 2" for one ask and "1 of 3" for its neighbour reads as a
 *   bug.
 * - **Nobody approves their own leave.** A head asking for time off drops
 *   to their manager for the department step, and a holder of the HR role
 *   loses the HR step entirely — the engine cannot exclude one holder from
 *   a role step, so the step is omitted instead.
 *
 * A step nobody can act on is still *emitted* candidate-less (the engine
 * marks it skipped, P1.9) rather than omitted — except the self-approval
 * HR step, which is a routing fact, not a missing person. The chain
 * therefore always shows its work: pending, skipped, approved.
 *
 * Reads the employee's `department → head` relation walk for the middle
 * step. That traversal stays inside this class on purpose: the head is an
 * attribute of the employee's assignment (like `manager_id`), and an
 * Org-context resolver built for this one caller would be an abstraction
 * with an audience of one. The manager leg goes through `ReportingLine`,
 * the context that owns the employee graph.
 */
class LeaveApprovalRouting
{
    public function __construct(private readonly ReportingLine $reporting) {}

    /**
     * @return list<ApproverSpec>
     */
    public function stepsFor(Employee $employee): array
    {
        $manager = $this->reporting->managerOf($employee);

        $steps = [new ApproverSpec(ApproverType::Manager, userId: $manager?->user_id, employeeId: $manager?->id)];
        $steps[] = $this->departmentStepFor($employee, $manager);

        $hrStep = $this->hrStepFor($employee);

        if ($hrStep !== null) {
            $steps[] = $hrStep;
        }

        return $steps;
    }

    /**
     * The department step: the head, or the manager when the department
     * names none or names the requester. Nothing only when nobody at all
     * can act — a candidate-less step is skipped visibly by the engine,
     * never silently dropped.
     */
    private function departmentStepFor(Employee $employee, ?Employee $manager): ApproverSpec
    {
        $head = $employee->department?->head;

        $candidate = $head !== null && (int) $head->id !== (int) $employee->id
            ? $head
            : $manager;

        return new ApproverSpec(ApproverType::DepartmentHead, userId: $candidate?->user_id, employeeId: $candidate?->id);
    }

    /**
     * The HR step: anyone holding the role — unless the requester holds it,
     * in which case the step is omitted rather than self-approved. A tenant
     * with no such role emits the step candidate-less and the engine skips
     * it, visibly.
     */
    private function hrStepFor(Employee $employee): ?ApproverSpec
    {
        $roleId = Role::query()->where('slug', 'hr_manager')->value('id');

        if ($roleId === null) {
            return new ApproverSpec(ApproverType::Role, roleId: null);
        }

        $holder = $employee->user_id !== null
            && User::query()
                ->whereKey($employee->user_id)
                ->whereHas('roles', fn ($query) => $query->where('roles.id', $roleId))
                ->exists();

        if ($holder) {
            return null;
        }

        return ApproverSpec::role((int) $roleId);
    }
}
