<?php

namespace App\Services\Hrms\Org;

use App\Enums\Hrms\OrgUnitType;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Org\EmployeeOrgUnit;
use App\Models\Hrms\Org\OrgUnit;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Org/HRMS — business units, legal entities and cost centres.
 *
 * Typed nodes beside the department tree. The rules: a parent must be of the
 * same type and may not create a loop; a unit with children or people cannot be
 * deleted (deactivate instead — payroll/finance reports still name it); and a
 * person sits in at most one unit per type, so assigning replaces the previous
 * seat of that type rather than adding a second.
 */
class OrgUnitService
{
    private const MAX_DEPTH = 20;

    public function __construct(private readonly HrmsAuditLogger $audit) {}

    /** @return Collection<int, OrgUnit> */
    public function all(?OrgUnitType $type = null): Collection
    {
        return OrgUnit::query()
            ->when($type !== null, fn ($q) => $q->where('type', $type->value))
            ->withCount('assignments')
            ->with('head:id,employee_code,name')
            ->orderBy('type')->orderBy('position')->orderBy('name')
            ->get();
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data, ?User $actor = null): OrgUnit
    {
        $type = OrgUnitType::from($data['type']);
        $this->assertParent($type, $data['parent_id'] ?? null, null);

        $unit = OrgUnit::create($data);
        $this->audit->log($unit, 'org_unit.created', null, $this->snapshot($unit), $actor);

        return $unit->refresh();
    }

    /** @param  array<string, mixed>  $data */
    public function update(OrgUnit $unit, array $data, ?User $actor = null): OrgUnit
    {
        unset($data['type']); // a unit never changes type: its seats and children were validated against it
        if (array_key_exists('parent_id', $data)) {
            $this->assertParent($unit->type, $data['parent_id'], $unit);
        }

        $before = $this->snapshot($unit);
        $unit->update($data);
        $this->audit->log($unit, 'org_unit.updated', $before, $this->snapshot($unit->refresh()), $actor);

        return $unit;
    }

    public function delete(OrgUnit $unit, ?User $actor = null): void
    {
        if ($unit->children()->exists()) {
            throw ValidationException::withMessages(['form' => 'This unit has child units. Move or delete them first.']);
        }

        if ($unit->assignments()->exists()) {
            throw ValidationException::withMessages(['form' => 'People still sit in this unit. Reassign them, or deactivate the unit instead.']);
        }

        $this->audit->log($unit, 'org_unit.deleted', $this->snapshot($unit), null, $actor);
        $unit->delete();
    }

    /**
     * Seat people in a unit, replacing their previous seat of the same type.
     *
     * @param  array<int, int>  $employeeIds
     */
    public function assign(OrgUnit $unit, array $employeeIds, ?User $actor = null): int
    {
        if (! $unit->is_active) {
            throw ValidationException::withMessages(['form' => 'That unit is inactive — pick a current one.']);
        }

        return DB::transaction(function () use ($unit, $employeeIds, $actor): int {
            $count = 0;
            foreach (Employee::query()->whereIn('id', $employeeIds)->get() as $employee) {
                EmployeeOrgUnit::query()->updateOrCreate(
                    ['employee_id' => $employee->id, 'unit_type' => $unit->type->value],
                    ['org_unit_id' => $unit->id],
                );
                $count++;
            }

            $this->audit->log($unit, 'org_unit.assigned', null, ['employee_ids' => array_slice($employeeIds, 0, 100), 'count' => $count], $actor);

            return $count;
        });
    }

    /** @param  array<int, int>  $employeeIds */
    public function unassign(OrgUnit $unit, array $employeeIds, ?User $actor = null): int
    {
        $count = EmployeeOrgUnit::query()->where('org_unit_id', $unit->id)->whereIn('employee_id', $employeeIds)->delete();
        $count > 0 && $this->audit->log($unit, 'org_unit.unassigned', null, ['employee_ids' => array_slice($employeeIds, 0, 100), 'count' => $count], $actor);

        return $count;
    }

    /** @return Collection<int, array{id: int, employee_code: string, name: string}> */
    public function members(OrgUnit $unit): Collection
    {
        return EmployeeOrgUnit::query()->where('org_unit_id', $unit->id)->with('employee:id,employee_code,name,preferred_name')->get()
            ->map(fn (EmployeeOrgUnit $row): array => [
                'id' => $row->employee->id,
                'employee_code' => $row->employee->employee_code,
                'name' => $row->employee->displayName(),
            ])->values();
    }

    private function assertParent(OrgUnitType $type, ?int $parentId, ?OrgUnit $self): void
    {
        if ($parentId === null) {
            return;
        }

        $parent = OrgUnit::query()->find($parentId);

        if ($parent === null || $parent->type !== $type) {
            throw ValidationException::withMessages(['parent_id' => 'The parent must be a '.mb_strtolower($type->label()).'.']);
        }

        // Walk up from the proposed parent: meeting the unit itself means a loop.
        $cursor = $parent;
        for ($i = 0; $i < self::MAX_DEPTH && $cursor !== null; $i++) {
            if ($self !== null && $cursor->id === $self->id) {
                throw ValidationException::withMessages(['parent_id' => 'A unit cannot sit under itself or one of its own children.']);
            }
            $cursor = $cursor->parent_id === null ? null : OrgUnit::query()->find($cursor->parent_id);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(OrgUnit $unit): array
    {
        return $unit->only(['name', 'code', 'parent_id', 'head_employee_id', 'is_active']) + ['type' => $unit->type->value];
    }
}
