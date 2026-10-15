<?php

namespace App\Services\Hrms\Org;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Org/HRMS — the two bookkeeping rules every org catalog shares.
 *
 * Both are trivial on their own and identical across departments,
 * designations and locations, which is exactly why they live here: the
 * alternative is three copies that drift, and "Engineering" and "Engineering-2"
 * disagreeing about which is which is a bug report nobody can reproduce.
 */
class OrgNaming
{
    /**
     * A slug for `$name` that no other row in `$table` is using.
     *
     * Appends `-2`, `-3`, … on collision, matching `WorkspaceService`. The
     * first number is skipped because `-1` reads like a version number rather
     * than a collision, and a tenant with one "Engineering" and one
     * "Engineering-2" has to be able to guess which is which.
     *
     * @param  class-string<Model>  $model
     * @param  int|null  $ignoreId  the row being updated keeps its own slug
     */
    public function uniqueSlug(string $model, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        $candidate = $base;
        $suffix = 1;

        while ($this->isTaken($model, $candidate, $ignoreId)) {
            $candidate = $base.'-'.(++$suffix);
        }

        return $candidate;
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function isTaken(string $model, string $slug, ?int $ignoreId): bool
    {
        return $model::query()
            ->where('slug', $slug)
            // A rename to the slug the row already has must not collide with
            // itself, or "renaming" a department to its own name 422s.
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();
    }

    /**
     * The sibling order a reorder request asks for.
     *
     * Submitted ids are intersected with the real siblings *first*. A reorder
     * names the members of one list; an id that is not a member of it is not
     * this list's business to move, and honouring it would let a payload drag a
     * department out of a different parent just by naming it.
     *
     * Siblings the payload omitted keep their relative order at the end, so a
     * stale or partial list cannot silently drop a row off the end.
     *
     * @param  list<int>  $orderedIds
     * @param  list<int>  $siblings
     * @return list<int>
     */
    public function order(array $orderedIds, array $siblings): array
    {
        $requested = array_values(array_intersect($orderedIds, $siblings));

        return array_values(array_unique(array_merge(
            $requested,
            array_diff($siblings, $requested),
        )));
    }

    /**
     * Renumber a sibling list 1..N in its current order.
     *
     * Positions are stored rather than derived from a sort column because a
     * department's siblings are a *different* set from its parent's, so a
     * single global sort cannot express the order. Renumbering instead of
     * swapping two values keeps the set gap-free after a deletion, which is
     * what stops a drag-and-drop reorder from drifting apart over months of
     * edits.
     *
     * @param  iterable<int, Model>  $models
     */
    public function renumber(iterable $models): void
    {
        $position = 0;

        foreach ($models as $model) {
            $model->position = ++$position;
            $model->save();
        }
    }
}
