<?php

namespace App\Services\Hrms\Org;

use App\Models\Hrms\Org\Department;
use Illuminate\Validation\ValidationException;

/**
 * Org/HRMS — the department tree.
 *
 * Its own class because the interesting part is not the parent pointer, it is
 * everything that walks it. Three separate features follow `parent_id` upward
 * and downward — the org chart, "this department and everything under it" for
 * a payroll scope, and the cycle check when somebody re-parents a node — and a
 * single careless loop in this data turns all three into unbounded traversals.
 * Stated once, here, so they cannot drift apart.
 *
 * Every walk carries a `seen` set, so a cycle that is *already* in the data
 * terminates instead of hanging a request. {@see assertCanReparent()} prevents
 * new ones; it cannot be the only thing standing between an imported org table
 * and a request timeout.
 */
class DepartmentTree
{
    /**
     * Re-parent a department, or refuse.
     *
     * @throws ValidationException 422 on a self-parent or a cycle
     */
    public function reparent(Department $department, ?Department $parent): Department
    {
        $this->assertCanReparent($department, $parent);

        $department->parent_id = $parent?->id;
        $department->save();

        return $department;
    }

    /**
     * @throws ValidationException 422
     */
    public function assertCanReparent(Department $department, ?Department $parent): void
    {
        if ($parent === null) {
            return;
        }

        if ($parent->id === $department->id) {
            throw ValidationException::withMessages([
                'parent_id' => 'A department cannot be its own parent.',
            ]);
        }

        // Making a node a child of something already inside its own subtree does
        // not create a cycle the moment it is written — it creates one a few
        // levels further down, which is exactly the corruption that surfaces
        // later as an org chart that renders half-empty.
        if ($this->contains($department, $parent->id)) {
            throw ValidationException::withMessages([
                'parent_id' => sprintf(
                    '%s is already inside %s, so this would create a loop.',
                    $parent->name,
                    $department->name,
                ),
            ]);
        }
    }

    /**
     * Every department beneath `$department`, breadth-first.
     *
     * @return list<Department>
     */
    public function descendantsOf(Department $department): array
    {
        $found = [];
        $seen = [$department->id => true];
        $frontier = [$department->id];

        while ($frontier !== []) {
            $children = Department::whereIn('parent_id', $frontier)->orderBy('position')->get();

            // The frontier is the *next* level, not this one: carrying this
            // level forward is what turns a cycle in the data into a loop.
            $frontier = [];

            foreach ($children as $child) {
                if (isset($seen[$child->id])) {
                    continue;
                }

                $seen[$child->id] = true;
                $found[] = $child;
                $frontier[] = $child->id;
            }
        }

        return $found;
    }

    /**
     * The chain from the root down to `$department`, inclusive.
     *
     * Walks *up* from the node, so a cycle in the data would never terminate —
     * hence the `$seen` break. Breadth-first from the roots would not have that
     * problem, but would cost a query per level for a list the UI only uses to
     * render a breadcrumb.
     *
     * @return list<Department>
     */
    public function pathTo(Department $department): array
    {
        $path = [$department->id => $department];
        $node = $department;

        while ($node->parent_id !== null) {
            if (isset($path[$node->parent_id])) {
                break;
            }

            $parent = Department::find($node->parent_id);

            if ($parent === null) {
                break;
            }

            $path[$parent->id] = $parent;
            $node = $parent;
        }

        // The walk goes up, a breadcrumb comes down.
        return array_values(array_reverse($path));
    }

    /**
     * Whether `$childId` sits anywhere beneath `$ancestor`.
     */
    private function contains(Department $ancestor, int $childId): bool
    {
        foreach ($this->descendantsOf($ancestor) as $descendant) {
            if ($descendant->id === $childId) {
                return true;
            }
        }

        return false;
    }
}
