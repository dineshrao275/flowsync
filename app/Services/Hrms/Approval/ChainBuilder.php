<?php

namespace App\Services\Hrms\Approval;

use App\Enums\Hrms\ApprovalStepMode;
use App\Enums\Hrms\ApproverType;
use App\Models\Hrms\Employee\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\Hrms\Employee\ReportingLine;
use App\Services\Hrms\Shared\ValueObjects\ApproverSpec;

/**
 * Approval/HRMS — turns a domain's chain template into engine steps for one
 * requester (P2.4 / P2.5).
 *
 * This is where "who is the manager / department head / holder of a role"
 * gets decided, because it is the context allowed to read the employee graph
 * (the engine itself never does). Rules, carried over verbatim from the
 * hard-coded chains this replaced:
 *
 *  - a step nobody can act on is still *emitted* candidate-less, so the
 *    engine skips it visibly instead of the chain silently changing shape;
 *  - `department_head` falls back to the manager when the department names no
 *    head or names the requester (nobody approves their own ask);
 *  - a `role` step flagged `omit_if_requester_holds` is dropped when the
 *    requester holds the role (the engine cannot exclude one role holder);
 *  - a `when` condition that does not match the request payload drops the
 *    step; a chain emptied that way settles auto-approved, audited, through
 *    the engine's dead-on-arrival path.
 */
class ChainBuilder
{
    public function __construct(
        private readonly ChainTemplates $templates,
        private readonly ReportingLine $reporting,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  condition inputs: amount, days, percent …
     * @return list<ApproverSpec>
     */
    public function stepsFor(string $domain, Employee $employee, array $payload = []): array
    {
        $template = $this->templates->for($domain);
        $manager = $this->reporting->managerOf($employee);
        $payload += ['department_id' => $employee->department_id];

        $specs = [];
        $stages = [];

        foreach ($template['steps'] as $step) {
            if (! $this->applies($step['when'] ?? null, $payload)) {
                continue;
            }

            $spec = $this->resolve($step, $employee, $manager);

            if ($spec === null) {
                continue;
            }

            $group = $step['stage'] ?? 'own-'.count($specs);

            if (! array_key_exists($group, $stages)) {
                $stages[$group] = [
                    'number' => count($stages) + 1,
                    'mode' => ApprovalStepMode::tryFrom((string) ($step['mode'] ?? '')) ?? ApprovalStepMode::Sequential,
                ];
            }

            $specs[] = $spec->inStage(
                $stages[$group]['number'],
                $stages[$group]['mode'],
                $step['sla_hours'] ?? $template['sla_hours'],
            );
        }

        // Every step was conditioned away: one candidate-less step lets the
        // engine auto-approve the ask, audited, rather than refusing an empty chain.
        return $specs !== [] ? $specs : [new ApproverSpec(ApproverType::Manager, stage: 1)];
    }

    /** @param array<string, mixed> $step */
    private function resolve(array $step, Employee $employee, ?Employee $manager): ?ApproverSpec
    {
        return match (ApproverType::from($step['type'])) {
            ApproverType::Manager => new ApproverSpec(ApproverType::Manager, userId: $manager?->user_id, employeeId: $manager?->id),
            ApproverType::DepartmentHead => $this->departmentHead($employee, $manager),
            ApproverType::Role => $this->roleStep($step, $employee),
            ApproverType::User => ApproverSpec::user((int) $step['user_id'], Employee::query()->where('user_id', $step['user_id'])->value('id')),
        };
    }

    private function departmentHead(Employee $employee, ?Employee $manager): ApproverSpec
    {
        $head = $employee->department?->head;

        $candidate = $head !== null && (int) $head->id !== (int) $employee->id ? $head : $manager;

        return new ApproverSpec(ApproverType::DepartmentHead, userId: $candidate?->user_id, employeeId: $candidate?->id);
    }

    /** @param array<string, mixed> $step */
    private function roleStep(array $step, Employee $employee): ?ApproverSpec
    {
        $roleId = isset($step['role_slug'])
            ? Role::query()->where('slug', $step['role_slug'])->value('id')
            : Role::query()
                ->whereHas('permissions', fn ($q) => $q->where('slug', $step['permission'] ?? ''))
                ->orderBy('id')
                ->value('id');

        if ($roleId === null) {
            return new ApproverSpec(ApproverType::Role, roleId: null);
        }

        $holder = ! empty($step['omit_if_requester_holds'])
            && $employee->user_id !== null
            && User::query()
                ->whereKey($employee->user_id)
                ->whereHas('roles', fn ($q) => $q->where('roles.id', $roleId))
                ->exists();

        return $holder ? null : ApproverSpec::role((int) $roleId);
    }

    /** @param array{field: string, op: string, value: float|int}|null $when @param array<string, mixed> $payload */
    private function applies(?array $when, array $payload): bool
    {
        if ($when === null) {
            return true;
        }

        $actual = $payload[$when['field']] ?? null;

        if ($actual === null) {
            // A condition on a value the request does not carry cannot be met.
            return false;
        }

        $actual += 0;

        return match ($when['op']) {
            '>' => $actual > $when['value'],
            '>=' => $actual >= $when['value'],
            '<' => $actual < $when['value'],
            '<=' => $actual <= $when['value'],
            '=' => $actual == $when['value'],
            '!=' => $actual != $when['value'],
            default => false,
        };
    }
}
