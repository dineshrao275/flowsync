<?php

namespace App\Services\Hrms\Shift;

use App\Models\Hrms\Attendance\AttendanceRotation;
use App\Models\Hrms\Attendance\AttendanceShift;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Shift/HRMS — the rotation templates catalogue ("6 on, 2 off").
 *
 * The cycle is a JSON list, which cannot carry a foreign key, so every shift
 * id in it is verified here at write time; deleting a rotation never touches
 * the roster rows it already produced.
 */
class RotationService
{
    public function __construct(private readonly HrmsAuditLogger $audit) {}

    /** @return Collection<int, AttendanceRotation> */
    public function rotations(): Collection
    {
        return AttendanceRotation::query()->orderBy('name')->get();
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data, ?User $actor = null): AttendanceRotation
    {
        $this->assertCycle($data['cycle']);
        $rotation = AttendanceRotation::create($data);
        $this->audit->log($rotation, 'rotation.created', null, ['code' => $rotation->code, 'cycle' => $rotation->cycle], $actor);

        return $rotation->refresh();
    }

    /** @param  array<string, mixed>  $data */
    public function update(AttendanceRotation $rotation, array $data, ?User $actor = null): AttendanceRotation
    {
        if (isset($data['cycle'])) {
            $this->assertCycle($data['cycle']);
        }

        $before = $rotation->only(['name', 'cycle', 'is_active']);
        $rotation->update($data);
        $this->audit->log($rotation, 'rotation.updated', $before, $rotation->only(['name', 'cycle', 'is_active']), $actor);

        return $rotation->refresh();
    }

    public function delete(AttendanceRotation $rotation, ?User $actor = null): void
    {
        $this->audit->log($rotation, 'rotation.deleted', $rotation->only(['code', 'cycle']), null, $actor);
        $rotation->delete();
    }

    /** @param  array<int, int|null>  $cycle */
    private function assertCycle(array $cycle): void
    {
        $ids = array_values(array_unique(array_filter($cycle, fn ($slot) => $slot !== null)));

        if ($ids === []) {
            throw ValidationException::withMessages(['cycle' => 'A rotation needs at least one working day.']);
        }

        if (AttendanceShift::query()->whereIn('id', $ids)->where('is_active', true)->count() !== count($ids)) {
            throw ValidationException::withMessages(['cycle' => 'Every slot must be an active shift or a day off.']);
        }
    }
}
