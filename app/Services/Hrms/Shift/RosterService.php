<?php

namespace App\Services\Hrms\Shift;

use App\Models\Hrms\Attendance\AttendanceRoster;
use App\Models\Hrms\Attendance\AttendanceRotation;
use App\Models\Hrms\Attendance\AttendanceShift;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shift/HRMS — who works which shift, when.
 *
 * The roster table is the authoritative source the day computation reads, and
 * its unique `(employee_id, effective_from)` index refuses overlaps — so every
 * write here first *clears* the target window (truncating, splitting or
 * removing what it overlaps) and then inserts. A rotation is applied by
 * materialising ordinary roster rows: a day off is a row with no shift and all
 * seven weekly-off flags set, which the existing weekly-off reader already
 * understands.
 */
class RosterService
{
    private const MAX_WINDOW_DAYS = 366;

    private const MAX_EMPLOYEES = 200;

    public function __construct(private readonly HrmsAuditLogger $audit) {}

    /**
     * Rosters overlapping a window, optionally for some employees.
     *
     * @param  array<int, int>|null  $employeeIds
     * @return Collection<int, AttendanceRoster>
     */
    public function between(Carbon $from, Carbon $to, ?array $employeeIds = null): Collection
    {
        return AttendanceRoster::query()
            ->whereDate('effective_from', '<=', $to->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from->toDateString()))
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->with(['employee', 'shift'])
            ->orderBy('employee_id')
            ->orderBy('effective_from')
            ->limit(2000)
            ->get();
    }

    /**
     * Put one person on a shift (or, with a null shift, on a flexible/no-shift
     * stretch) over a date range, replacing whatever it overlaps.
     *
     * @param  array<int, int>|null  $weeklyOffs  seven Monday-first 0/1 flags
     */
    public function assign(Employee $employee, ?int $shiftId, Carbon $from, ?Carbon $to, ?array $weeklyOffs, bool $flexible, ?User $actor = null): AttendanceRoster
    {
        $this->assertShift($shiftId);
        $this->assertRange($from, $to);

        return DB::transaction(function () use ($employee, $shiftId, $from, $to, $weeklyOffs, $flexible, $actor): AttendanceRoster {
            $this->clearRange($employee->id, $from, $to);

            $roster = AttendanceRoster::create([
                'employee_id' => $employee->id,
                'shift_id' => $shiftId,
                'effective_from' => $from->toDateString(),
                'effective_to' => $to?->toDateString(),
                'weekly_offs' => $weeklyOffs ?? $this->defaultOffs($shiftId),
                'is_flexible' => $flexible,
            ]);

            $this->audit->log($roster, 'roster.assigned', null, [
                'employee_id' => $employee->id,
                'shift_id' => $shiftId,
                'effective_from' => $from->toDateString(),
                'effective_to' => $to?->toDateString(),
            ], $actor);

            return $roster->load(['employee', 'shift']);
        });
    }

    public function remove(AttendanceRoster $roster, ?User $actor = null): void
    {
        DB::transaction(function () use ($roster, $actor): void {
            $this->audit->log($roster, 'roster.removed', [
                'employee_id' => $roster->employee_id,
                'shift_id' => $roster->shift_id,
                'effective_from' => $roster->effective_from->toDateString(),
            ], null, $actor);
            $roster->delete();
        });
    }

    /**
     * Apply a rotation to people over a window. `$offset` shifts where in the
     * cycle the window starts, so a team can be staggered across the cycle.
     *
     * @param  array<int, int>  $employeeIds
     * @return array{employees: int, rows: int}
     */
    public function applyRotation(AttendanceRotation $rotation, array $employeeIds, Carbon $from, Carbon $to, int $offset, ?User $actor = null): array
    {
        $this->assertRange($from, $to);
        if ($to->diffInDays($from, true) > self::MAX_WINDOW_DAYS) {
            throw ValidationException::withMessages(['to' => 'A rotation can be applied for at most a year at a time.']);
        }
        if (count($employeeIds) > self::MAX_EMPLOYEES) {
            throw ValidationException::withMessages(['employee_ids' => 'Apply a rotation to at most '.self::MAX_EMPLOYEES.' people at a time.']);
        }
        if (! $rotation->is_active) {
            throw ValidationException::withMessages(['form' => 'This rotation is inactive.']);
        }

        $cycle = array_values($rotation->cycle);
        $segments = $this->segments($cycle, $from, $to, $offset);
        $rows = 0;

        DB::transaction(function () use ($employeeIds, $segments, $from, $to, $rotation, $actor, &$rows): void {
            foreach (Employee::query()->whereIn('id', $employeeIds)->get() as $employee) {
                $this->clearRange($employee->id, $from, $to);
                foreach ($segments as [$shiftId, $start, $end]) {
                    AttendanceRoster::create([
                        'employee_id' => $employee->id,
                        'shift_id' => $shiftId,
                        'effective_from' => $start->toDateString(),
                        'effective_to' => $end->toDateString(),
                        'weekly_offs' => $shiftId === null ? [1, 1, 1, 1, 1, 1, 1] : [0, 0, 0, 0, 0, 0, 0],
                        'is_flexible' => false,
                    ]);
                    $rows++;
                }
            }

            $this->audit->log($rotation, 'roster.rotation_applied', null, [
                'employee_ids' => array_slice($employeeIds, 0, 100),
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'rows' => $rows,
            ], $actor);
        });

        return ['employees' => count($employeeIds), 'rows' => $rows];
    }

    /**
     * Collapse the cycle over a window into runs of the same slot.
     *
     * @param  array<int, int|null>  $cycle
     * @return array<int, array{0: int|null, 1: Carbon, 2: Carbon}>
     */
    private function segments(array $cycle, Carbon $from, Carbon $to, int $offset): array
    {
        $segments = [];
        $length = count($cycle);

        for ($day = $from->copy(), $i = 0; $day->lte($to); $day->addDay(), $i++) {
            $slot = $cycle[(($i + $offset) % $length + $length) % $length];
            $last = count($segments) - 1;

            if ($last >= 0 && $segments[$last][0] === $slot) {
                $segments[$last][2] = $day->copy();
            } else {
                $segments[] = [$slot, $day->copy(), $day->copy()];
            }
        }

        return $segments;
    }

    /** Free the window for one employee: truncate, split or remove overlapping rows. */
    private function clearRange(int $employeeId, Carbon $from, ?Carbon $to): void
    {
        $overlapping = AttendanceRoster::query()
            ->where('employee_id', $employeeId)
            ->when($to !== null, fn ($q) => $q->whereDate('effective_from', '<=', $to->toDateString()))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from->toDateString()))
            ->get();

        foreach ($overlapping as $row) {
            $startsBefore = $row->effective_from->toDateString() < $from->toDateString();
            $endsAfter = $to !== null && ($row->effective_to === null || $row->effective_to->toDateString() > $to->toDateString());

            if ($startsBefore && $endsAfter) {
                AttendanceRoster::create([
                    ...$row->only(['employee_id', 'shift_id', 'weekly_offs', 'is_flexible']),
                    'effective_from' => $to->copy()->addDay()->toDateString(),
                    'effective_to' => $row->effective_to?->toDateString(),
                ]);
                $row->update(['effective_to' => $from->copy()->subDay()->toDateString()]);
            } elseif ($startsBefore) {
                $row->update(['effective_to' => $from->copy()->subDay()->toDateString()]);
            } elseif ($endsAfter) {
                $row->update(['effective_from' => $to->copy()->addDay()->toDateString()]);
            } else {
                $row->delete();
            }
        }
    }

    /** @return array<int, int> */
    private function defaultOffs(?int $shiftId): array
    {
        $days = $shiftId !== null ? AttendanceShift::query()->whereKey($shiftId)->value('working_days') : null;
        $days = is_string($days) ? json_decode($days, true) : $days;

        if (empty($days)) {
            return [0, 0, 0, 0, 0, 1, 1];
        }

        return array_map(fn (string $d): int => in_array($d, $days, true) ? 0 : 1, ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']);
    }

    private function assertShift(?int $shiftId): void
    {
        if ($shiftId !== null && ! AttendanceShift::query()->whereKey($shiftId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['shift_id' => 'Pick an active shift.']);
        }
    }

    private function assertRange(Carbon $from, ?Carbon $to): void
    {
        if ($to !== null && $to->lt($from)) {
            throw ValidationException::withMessages(['effective_to' => 'The end date cannot be before the start date.']);
        }
    }
}
