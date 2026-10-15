<?php

namespace App\Services\Hrms\Org;

use App\Models\Hrms\Org\Department;
use Illuminate\Support\Facades\DB;

/**
 * Org/HRMS — the department chart, as a read model.
 *
 * The nesting and the headcounts live here, away from {@see DepartmentTree},
 * because they are a different kind of question. DepartmentTree answers "is
 * this structure safe to walk"; this one answers "draw it". Splitting them
 * keeps each answerable without reading the other, and keeps the cycle guards
 * in one place that a future change cannot route around.
 *
 * Two queries for the entire org regardless of size: the departments, and one
 * grouped employee count. The naive version counts employees per node while
 * rendering, which is the textbook N+1 on the most-requested HRMS screen in the
 * product.
 */
class DepartmentChart
{
    /**
     * The whole tree as nested nodes, with a headcount at every level.
     *
     * `headcount` is the **subtree** total (this node plus everything under it)
     * and `direct_count` is this node alone. Both are carried because they are
     * routinely confused, and a parent department showing 1 while it "contains"
     * its entire company is that confusion.
     *
     * @return list<array<string, mixed>>
     */
    public function build(): array
    {
        // Counted once, up front: reading it per node would be the N+1 this
        // method exists to avoid.
        $counts = $this->directCounts();
        $children = [];

        foreach (Department::orderBy('position')->orderBy('name')->get() as $department) {
            $children[$this->key($department->parent_id)][] = $this->node($department, $counts);
        }

        $visited = [];
        $tree = $this->roots($children, $visited);

        return array_merge($tree, $this->unreachable($children, $visited));
    }

    /**
     * Everything that hangs off the top of the org.
     *
     * @param  array<string, list<array<string, mixed>>>  $children
     * @param  array<int, true>  $visited
     * @return list<array<string, mixed>>
     */
    private function roots(array $children, array &$visited): array
    {
        $tree = [];

        foreach ($children['root'] ?? [] as $root) {
            if (($node = $this->nest($root, $children, 0, $visited, [])) !== null) {
                $tree[] = $node;
            }
        }

        return $tree;
    }

    /**
     * Whatever the root pass could not reach — a `parent_id` pointing at a
     * deleted row, or a cycle in imported data — surfaced at the top level
     * rather than dropped. A department that exists but renders nowhere is
     * silent data loss; surfacing it is what makes it fixable.
     *
     * @param  array<string, list<array<string, mixed>>>  $children
     * @param  array<int, true>  $visited
     * @return list<array<string, mixed>>
     */
    private function unreachable(array $children, array &$visited): array
    {
        $tree = [];

        foreach ($children as $key => $group) {
            foreach ($group as $node) {
                if (isset($visited[$node['id']])) {
                    continue;
                }

                if (($root = $this->nest($node, $children, 0, $visited, [])) !== null) {
                    $tree[] = $root;
                }
            }

            unset($children[$key]);
        }

        return $tree;
    }

    /**
     * Nest one node's children beneath it, carrying the headcount up.
     *
     * Depth is assigned during the walk down rather than derived from a parent's
     * already-computed depth, because a child can sort *before* its parent
     * (`position` is per-parent, not global) and a single pass in query order
     * would then compute the wrong depth.
     *
     * Returns null for a node that is already on this branch — a cycle in the
     * data — so the caller prunes it. Re-emitting it instead would draw the
     * same department twice and count its staff twice.
     *
     * @param  array<string, list<array<string, mixed>>>  $children
     * @param  array<int, true>  $visited
     * @param  array<int, true>  $path
     * @return array<string, mixed>|null
     */
    private function nest(array $node, array $children, int $depth, array &$visited, array $path): ?array
    {
        if (isset($path[$node['id']])) {
            return null;
        }

        $node['depth'] = $depth;
        $node['departments'] = [];
        $visited[$node['id']] = true;
        $path[$node['id']] = true;

        // The subtree total starts at this node's own people, not at zero:
        // a leaf department with three staff reports 3, not 0.
        $node['headcount'] = $node['direct_count'];

        foreach ($children[(string) $node['id']] ?? [] as $child) {
            $nested = $this->nest($child, $children, $depth + 1, $visited, $path);

            if ($nested === null) {
                continue;
            }

            $node['departments'][] = $nested;
            $node['headcount'] += $nested['headcount'];
        }

        return $node;
    }

    /**
     * @param  array<int, int>  $counts
     * @return array<string, mixed>
     */
    private function node(Department $department, array $counts): array
    {
        return [
            'id' => $department->id,
            'name' => $department->name,
            'slug' => $department->slug,
            'code' => $department->code,
            'parent_id' => $department->parent_id,
            'is_active' => $department->is_active,
            'head_employee_id' => $department->head_employee_id,
            'direct_count' => $counts[$department->id] ?? 0,
            'headcount' => 0,
            'depth' => 0,
            'departments' => [],
        ];
    }

    /**
     * How many people sit directly in each department, in one query.
     *
     * Soft-deleted employees are excluded: a person who has left does not
     * inflate the headcount a manager sees when they are deciding whether a
     * department needs a head.
     *
     * @return array<int, int>
     */
    private function directCounts(): array
    {
        $rows = DB::table('employees')
            ->whereNull('deleted_at')
            ->whereNotNull('department_id')
            ->groupBy('department_id')
            ->selectRaw('department_id, count(*) as headcount')
            ->pluck('headcount', 'department_id');

        return $rows->map(fn ($count): int => (int) $count)->all();
    }

    /**
     * Array key for a parent id, with a stable stand-in for the roots —
     * a PHP array cannot use null as a key.
     */
    private function key(?int $parentId): string
    {
        return $parentId === null ? 'root' : (string) $parentId;
    }
}
