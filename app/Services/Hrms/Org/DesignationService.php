<?php

namespace App\Services\Hrms\Org;

use App\Models\Hrms\Org\Designation;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Org/HRMS — designation writes.
 *
 * A flat list with one optional link (a department), so there is no tree and no
 * cycle rule here — that is the whole reason it is a separate class from
 * {@see DepartmentService} rather than a mode of it.
 */
class DesignationService
{
    public function __construct(private readonly OrgNaming $naming) {}

    /**
     * The flat list, ordered for the picker.
     */
    public function all(): Collection
    {
        return Designation::query()
            ->withCount('employees')
            ->orderBy('position')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array{name: string, code?: string|null, level?: int|null, department_id?: int|null, is_active?: bool}  $data
     */
    public function create(array $data): Designation
    {
        $designation = new Designation;
        $designation->name = $data['name'];
        $designation->slug = $this->naming->uniqueSlug(Designation::class, $data['name']);
        $designation->code = $data['code'] ?? null;
        $designation->level = $data['level'] ?? null;
        $designation->is_active = $data['is_active'] ?? true;
        $designation->position = ((int) Designation::query()->max('position')) + 1;
        $designation->department_id = $data['department_id'] ?? null;
        $designation->save();

        return $designation->refresh();
    }

    /**
     * @param  array{name?: string, code?: string|null, level?: int|null, department_id?: int|null, is_active?: bool}  $data
     */
    public function update(Designation $designation, array $data): Designation
    {
        if (array_key_exists('name', $data) && $data['name'] !== $designation->name) {
            $designation->name = $data['name'];
            $designation->slug = $this->naming->uniqueSlug(Designation::class, $data['name'], $designation->id);
        }

        // Only the keys the payload actually carries. A partial update that
        // assigned `?? null` to every optional field would clear a designation's
        // department and level on every unrelated edit.
        foreach (['code', 'level', 'department_id', 'is_active'] as $field) {
            if (array_key_exists($field, $data)) {
                $designation->{$field} = $data[$field];
            }
        }

        $designation->save();

        return $designation->refresh();
    }

    /**
     * @param  list<int>  $orderedIds
     */
    public function reorder(array $orderedIds): void
    {
        $this->naming->renumber($this->ordered($orderedIds, Designation::query()->pluck('id')->all()));
    }

    /**
     * @throws ValidationException 422 while employees hold this designation
     */
    public function delete(Designation $designation): void
    {
        if ($designation->employees()->exists()) {
            throw ValidationException::withMessages([
                'form' => 'Employees still hold this designation. Move them to another one, or deactivate the designation instead.',
            ]);
        }

        $designation->delete();
    }

    public function deactivate(Designation $designation): Designation
    {
        $designation->is_active = false;
        $designation->save();

        return $designation;
    }

    /**
     * Apply a submitted id order, keeping anything the client omitted.
     *
     * @param  list<int>  $orderedIds
     * @param  list<int>  $siblings
     * @return list<Designation>
     */
    private function ordered(array $orderedIds, array $siblings): array
    {
        $ids = $this->naming->order($orderedIds, $siblings);
        $positions = array_flip($ids);

        return Designation::whereIn('id', $ids)->get()
            ->sortBy(fn (Designation $designation): int => $positions[$designation->id])
            ->values()
            ->all();
    }
}
