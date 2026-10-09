<?php

namespace App\Services\Hrms\Shift;

use App\Models\Hrms\Attendance\AttendanceShift;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shift/HRMS — the working-hours catalogue.
 *
 * Shifts are tenant configuration: creating and editing is free, deleting is
 * refused for starter patterns and for any shift a roster or an employee
 * default still points at (deactivate it instead). A split shift is described
 * by `segments`; the columns the day computation reads (`start_time`,
 * `end_time`, `break_minutes`, `is_night`) are then *derived* from the blocks —
 * first start, last end, the gaps as unpaid break — so nothing downstream
 * needs to know a shift is split.
 */
class ShiftService
{
    public function __construct(private readonly HrmsAuditLogger $audit) {}

    /** @return Collection<int, AttendanceShift> */
    public function shifts(): Collection
    {
        return AttendanceShift::query()
            ->withCount('rosters')
            ->orderBy('position')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): AttendanceShift
    {
        return DB::transaction(function () use ($data, $actor): AttendanceShift {
            $shift = AttendanceShift::create($this->derive($data) + ['is_system' => false]);

            $this->audit->log($shift, 'shift.created', null, $this->snapshot($shift), $actor);

            return $shift->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(AttendanceShift $shift, array $data, ?User $actor = null): AttendanceShift
    {
        return DB::transaction(function () use ($shift, $data, $actor): AttendanceShift {
            $before = $this->snapshot($shift);

            // A starter keeps its code (the seeder keys on it); everything else is the tenant's to tune.
            if ($shift->is_system) {
                unset($data['code']);
            }

            // Re-derive from the merged picture so a partial PUT of one time stays coherent.
            $merged = array_merge($shift->only(['start_time', 'end_time', 'break_minutes', 'segments']), $data);
            $retimed = ! array_key_exists('segments', $data) && (isset($data['start_time']) || isset($data['end_time']));
            if ($retimed || (array_key_exists('segments', $data) && empty($data['segments']))) {
                $merged['segments'] = null;
            }
            $shift->update($this->derive(array_merge($data, $merged)));

            $this->audit->log($shift, 'shift.updated', $before, $this->snapshot($shift), $actor);

            return $shift->refresh();
        });
    }

    public function delete(AttendanceShift $shift, ?User $actor = null): void
    {
        if ($shift->is_system) {
            throw ValidationException::withMessages(['form' => 'Starter shifts cannot be deleted. Deactivate it instead.']);
        }

        if ($shift->rosters()->exists() || Employee::query()->where('shift_id', $shift->id)->exists()) {
            throw ValidationException::withMessages(['form' => 'People are still rostered on this shift. Reassign them or deactivate the shift.']);
        }

        DB::transaction(function () use ($shift, $actor): void {
            $this->audit->log($shift, 'shift.deleted', $this->snapshot($shift), null, $actor);
            $shift->delete();
        });
    }

    /**
     * Set the employee-level default shift (the fallback the day computation
     * uses when no roster row covers the date). A null shift clears it.
     *
     * @param  array<int, int>  $employeeIds
     */
    public function assignDefault(?AttendanceShift $shift, array $employeeIds, ?User $actor = null): int
    {
        if ($shift !== null && ! $shift->is_active) {
            throw ValidationException::withMessages(['shift_id' => 'An inactive shift cannot be assigned.']);
        }

        return DB::transaction(function () use ($shift, $employeeIds, $actor): int {
            $count = Employee::query()->whereIn('id', $employeeIds)->update(['shift_id' => $shift?->id]);

            if ($shift !== null) {
                $this->audit->log($shift, 'shift.default_assigned', null, [
                    'employee_ids' => array_slice($employeeIds, 0, 100),
                    'count' => $count,
                ], $actor);
            }

            return $count;
        });
    }

    /**
     * Fold `segments` into the columns the day computation reads.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function derive(array $data): array
    {
        $segments = $data['segments'] ?? null;

        if (is_array($segments) && $segments !== []) {
            usort($segments, fn (array $a, array $b): int => strcmp($a['start'], $b['start']));
            $break = 0;
            for ($i = 1; $i < count($segments); $i++) {
                $break += $this->minutes($segments[$i]['start']) - $this->minutes($segments[$i - 1]['end']);
            }
            $data['segments'] = array_values($segments);
            $data['start_time'] = $segments[0]['start'];
            $data['end_time'] = $segments[count($segments) - 1]['end'];
            $data['break_minutes'] = $break;
        } else {
            $data['segments'] = null;
        }

        if (isset($data['start_time'], $data['end_time'])) {
            $data['is_night'] = $this->minutes($data['end_time']) <= $this->minutes($data['start_time']);
        }

        return $data;
    }

    private function minutes(string $time): int
    {
        return ((int) substr($time, 0, 2)) * 60 + (int) substr($time, 3, 2);
    }

    /** @return array<string, mixed> */
    private function snapshot(AttendanceShift $shift): array
    {
        return $shift->only(['name', 'code', 'start_time', 'end_time', 'break_minutes', 'grace_minutes', 'is_night', 'is_active', 'working_days', 'segments']);
    }
}
