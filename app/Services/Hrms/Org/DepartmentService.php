<?php

namespace App\Services\Hrms\Org;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Org\Department;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Org/HRMS — department writes.
 *
 * Reads live in {@see DepartmentTree}; this owns creation, edits, ordering and
 * retirement, so the controller has one service to call and one place where
 * "what a department may be" is decided.
 */
class DepartmentService
{
    public function __construct(
        private readonly DepartmentTree $tree,
        private readonly OrgNaming $naming,
    ) {}

    /**
     * The flat list, for pickers and the org page's detail column.
     *
     * Counts are eager-loaded: the org page asks for departments, designations
     * and locations together, and a count per row is the textbook N+1 on the
     * screen that asks for all three.
     */
    public function all(): Collection
    {
        return Department::query()
            ->with('head')
            ->withCount(['employees', 'children'])
            ->orderBy('position')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array{name: string, code?: string|null, parent_id?: int|null, head_employee_id?: int|null, description?: string|null, is_active?: bool}  $data
     */
    public function create(array $data): Department
    {
        $parent = $this->resolveParent($data['parent_id'] ?? null);
        $head = $this->resolveHead($data['head_employee_id'] ?? null);

        $department = new Department;
        $department->name = $data['name'];
        $department->slug = $this->naming->uniqueSlug(Department::class, $data['name']);
        $department->code = $data['code'] ?? null;
        $department->description = $data['description'] ?? null;
        $department->is_active = $data['is_active'] ?? true;
        // +1, not a 10-step, to match {@see OrgNaming::renumber()}: every
        // reorder collapses the set back to 1..N, so gaps would only reappear
        // and be normalized away again.
        $department->position = ((int) Department::where('parent_id', $parent?->id)->max('position')) + 1;
        $department->save();

        $this->place($department, $parent, $head);

        return $department->refresh();
    }

    /**
     * @param  array{name?: string, code?: string|null, parent_id?: int|null, head_employee_id?: int|null, description?: string|null, is_active?: bool}  $data
     *
     * @throws ValidationException 422 when a new parent would create a cycle
     */
    public function update(Department $department, array $data): Department
    {
        // Atomic, because a re-parent is validated *after* the scalars have
        // been applied. Without this, a request that renames a department and
        // asks for a cyclic parent commits the rename and then reports 422 —
        // a partial write on a rejected request.
        return DB::transaction(fn (): Department => $this->applyUpdate($department, $data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyUpdate(Department $department, array $data): Department
    {
        if (array_key_exists('name', $data) && $data['name'] !== $department->name) {
            $department->name = $data['name'];
            $department->slug = $this->naming->uniqueSlug(Department::class, $data['name'], $department->id);
        }

        foreach (['code', 'description', 'is_active'] as $field) {
            if (array_key_exists($field, $data)) {
                $department->{$field} = $data[$field];
            }
        }

        $department->save();

        // A partial update must not clear what it did not mention. The head is
        // the trap: it is set by its own picker, so "rename this department"
        // arrives without a head, and passing null for it would quietly strip
        // the team's manager off the org chart.
        if (array_key_exists('parent_id', $data) || array_key_exists('head_employee_id', $data)) {
            $this->place($department, $this->intendedParent($department, $data), $this->intendedHead($department, $data));
        }

        return $department->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function intendedParent(Department $department, array $data): ?Department
    {
        return array_key_exists('parent_id', $data)
            ? $this->resolveParent($data['parent_id'])
            : $this->currentParentOf($department);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function intendedHead(Department $department, array $data): ?Employee
    {
        if (array_key_exists('head_employee_id', $data)) {
            return $this->resolveHead($data['head_employee_id']);
        }

        // Untouched by this payload: keep whoever is there, re-resolved so the
        // active-employee rule does not have to be re-litigated on a rename.
        return $department->head_employee_id === null
            ? null
            : Employee::find($department->head_employee_id);
    }

    /**
     * Move a department among its siblings.
     *
     * Takes the whole ordered id list rather than a target index, so the client
     * and the server cannot disagree about what "position 2" means when two
     * people reorder the same list at once. The result is still gap-free
     * whatever order the ids arrive in.
     *
     * @param  list<int>  $orderedIds
     */
    public function reorder(?Department $parent, array $orderedIds): void
    {
        $siblings = Department::where('parent_id', $parent?->id)
            ->orderBy('position')
            ->pluck('id')
            ->all();

        $ordered = $this->naming->order($orderedIds, $siblings);

        $positions = array_flip($ordered);
        $position = 0;

        Department::whereIn('id', $ordered)->get()
            ->sortBy(fn (Department $department): int => $positions[$department->id])
            ->each(function (Department $department) use (&$position): void {
                $department->position = ++$position;
                $department->save();
            });
    }

    /**
     * Retire a department, or refuse if it is still in use.
     *
     * There is no hard delete: a department in a payslip's department field is
     * history, and removing the row would rewrite what payroll reported. An
     * empty leaf can still be deleted outright, because nothing references it
     * and an "Archive" button that refuses to archive an empty team is a
     * support ticket.
     *
     * @throws ValidationException 422 while it has children or employees
     */
    public function delete(Department $department): void
    {
        if ($department->employees()->exists()) {
            throw ValidationException::withMessages([
                'form' => 'This department still has employees. Move them to another department, or deactivate the department instead.',
            ]);
        }

        if ($department->children()->exists()) {
            throw ValidationException::withMessages([
                'form' => 'This department still has sub-departments. Move them, or deactivate the department instead.',
            ]);
        }

        // Designations are deliberately not in that guard. P3.1 made
        // `designations.department_id` nullable and the FK is nullOnDelete,
        // because most bands span departments — so a department going away
        // leaves its designations alive and simply unfiled, which is where a
        // band-less designation was allowed to live all along.
        $department->delete();
    }

    /**
     * Deactivate without deleting: the row stays, and stays attached to
     * historical records, but it disappears from every picker.
     */
    public function deactivate(Department $department): Department
    {
        $department->is_active = false;
        $department->save();

        return $department;
    }

    /**
     * Apply the parent and the head, refusing a cycle.
     *
     * Separate from `update()` because the two can be sent on their own — the
     * drag-and-drop re-parent and the "who runs this team" picker are
     * independent controls that both land here.
     *
     * @throws ValidationException 422
     */
    private function place(Department $department, ?Department $parent, ?Employee $head): void
    {
        $this->tree->reparent($department, $parent);

        $department->head_employee_id = $head?->id;
        $department->save();
    }

    private function currentParentOf(Department $department): ?Department
    {
        return $department->parent_id === null ? null : Department::find($department->parent_id);
    }

    private function resolveParent(?int $parentId): ?Department
    {
        return $parentId === null ? null : Department::findOrFail($parentId);
    }

    /**
     * A department head has to be somebody currently employed.
     *
     * Naming an exited employee as the head of a department makes the org chart
     * show somebody who no longer works here, and it is invisible in review
     * because the name resolves fine.
     *
     * @throws ValidationException 422
     */
    private function resolveHead(?int $headId): ?Employee
    {
        if ($headId === null) {
            return null;
        }

        $head = Employee::findOrFail($headId);

        if ($head->status !== EmployeeStatus::Active) {
            throw ValidationException::withMessages([
                'head_employee_id' => 'A department head has to be an active employee.',
            ]);
        }

        return $head;
    }
}
