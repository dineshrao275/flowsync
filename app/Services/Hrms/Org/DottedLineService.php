<?php

namespace App\Services\Hrms\Org;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Org\DottedLine;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Org/HRMS — the secondary (matrix) reporting lines.
 *
 * Informational by design: approvals, payroll and leave all follow the primary
 * `manager_id`, so a dotted line needs no cycle check — it can never make
 * someone their own approver. It still refuses a self-line, a duplicate of the
 * same kind, and a line to someone who has left.
 */
class DottedLineService
{
    public function __construct(private readonly HrmsAuditLogger $audit) {}

    /** @return Collection<int, DottedLine> */
    public function all(?int $employeeId = null, ?int $managerId = null): Collection
    {
        return DottedLine::query()
            ->when($employeeId !== null, fn ($q) => $q->where('employee_id', $employeeId))
            ->when($managerId !== null, fn ($q) => $q->where('manager_id', $managerId))
            ->with(['employee:id,employee_code,name,preferred_name', 'manager:id,employee_code,name,preferred_name'])
            ->orderBy('employee_id')->orderBy('id')
            ->limit(1000)
            ->get();
    }

    /** @param  array<string, mixed>  $data */
    public function add(array $data, ?User $actor = null): DottedLine
    {
        if ((int) $data['employee_id'] === (int) $data['manager_id']) {
            throw ValidationException::withMessages(['manager_id' => 'Nobody can have a dotted line to themselves.']);
        }

        $manager = Employee::query()->findOrFail($data['manager_id']);
        if (! $manager->status->isEmployed()) {
            throw ValidationException::withMessages(['manager_id' => 'That person has left the company.']);
        }

        if (DottedLine::query()->where($data['kind'] ? ['employee_id' => $data['employee_id'], 'manager_id' => $data['manager_id'], 'kind' => $data['kind']] : [])->exists()) {
            throw ValidationException::withMessages(['form' => 'That reporting line already exists.']);
        }

        $line = DottedLine::create($data + ['created_by' => $actor?->id]);
        $this->audit->log($line, 'org.dotted_line_added', null, $line->only(['employee_id', 'manager_id']) + ['kind' => $line->kind->value], $actor);

        return $line->refresh();
    }

    public function remove(DottedLine $line, ?User $actor = null): void
    {
        $this->audit->log($line, 'org.dotted_line_removed', $line->only(['employee_id', 'manager_id']) + ['kind' => $line->kind->value], null, $actor);
        $line->delete();
    }
}
