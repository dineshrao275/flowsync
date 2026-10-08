<?php

namespace App\Services\Hrms\Attendance;

use App\Enums\Hrms\AttendanceDayStatus;
use App\Enums\Hrms\PunchDirection;
use App\Models\Hrms\Attendance\AttendanceDay;
use App\Models\Hrms\Attendance\AttendancePunch;
use App\Models\Hrms\Attendance\AttendanceRoster;
use App\Models\Hrms\Attendance\AttendanceShift;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Shared\HrmsSetting;
use App\Services\Hrms\AttendanceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Attendance/HRMS — turning punches into days.
 *
 * Split from {@see AttendanceService} because pairing,
 * rounding and shift resolution are a self-contained decision: the math of
 * “how late is 09:17 against a 09:00 start with 15 minutes of grace” has
 * nothing to do with serving HTTP or stamping audit rows, and a second copy
 * of it anywhere else is how two screens disagree about the same morning.
 *
 * All math happens in the tenant’s configured timezone, never UTC and never
 * the server’s: a 09:00 start means 09:00 where the office is, and comparing
 * a UTC timestamp against a wall-clock shift is how a whole tenant’s
 * attendance goes wrong by exactly the offset, every day, unnoticed.
 */
class DayComputation
{
    /**
     * Recompute one person’s day from their punches, idempotently.
     *
     * Find-or-create on the unique pair, then recompute everything from the
     * current punches and the current roster: calling this twice, or after a
     * roster change, converges on the same row rather than duplicating it.
     */
    public function computeDay(Employee $employee, Carbon|string $date): AttendanceDay
    {
        $day = $date instanceof Carbon ? $date->copy()->startOfDay() : Carbon::parse((string) $date)->startOfDay();
        [$shift, $roster] = $this->resolveShift($employee, $day);

        return DB::transaction(function () use ($employee, $day, $shift, $roster): AttendanceDay {
            // whereDate, not where: the `date` cast serializes with a time
            // part on SQLite’s typeless columns, so an exact-string match
            // misses the row it just wrote and the unique pair 500s on the
            // second compute. PostgreSQL coerces either form; SQLite matches
            // bytes. whereDate is the spelling that is true on both.
            $record = AttendanceDay::where('employee_id', $employee->id)
                ->whereDate('work_date', $day->toDateString())
                ->first()
                ?? AttendanceDay::create([
                    'employee_id' => $employee->id,
                    'work_date' => $day->toDateString(),
                ]);

            $record->update($this->photograph($employee, $day, $shift, $roster));

            return $record->refresh();
        });
    }

    /**
     * The shift in force for a person on a date: the active roster wins, the
     * employee’s `shift_id` is the fallback, and null is a legitimate answer
     * — a person with no shift still punches, and the day still computes,
     * just without lateness math.
     *
     * @return array{AttendanceShift|null, AttendanceRoster|null}
     */
    public function resolveShift(Employee $employee, Carbon $date): array
    {
        $roster = AttendanceRoster::where('employee_id', $employee->id)
            ->activeOn($date->toDateString())
            ->orderByDesc('effective_from')
            ->first();

        if ($roster !== null) {
            // Used as assigned, even if the shift was since retired: the
            // roster row is the explicit assignment, and second-guessing it
            // here would silently recompute history.
            return [$roster->shift, $roster];
        }

        $shift = $employee->shift_id !== null ? AttendanceShift::find($employee->shift_id) : null;

        return [$shift, null];
    }

    /**
     * @return array<string, mixed>
     */
    private function photograph(Employee $employee, Carbon $day, ?AttendanceShift $shift, ?AttendanceRoster $roster): array
    {
        $punches = $this->punchesFor($employee, $day, $shift);
        [$firstIn, $lastOut, $worked] = $this->pair($punches);

        $settings = $this->thresholds();
        $break = $shift === null ? 0 : min($shift->break_minutes, $worked);
        $net = $worked - $break;

        $late = 0;
        $early = 0;

        if ($shift !== null && $firstIn !== null) {
            $deadline = $this->atTime($day, $shift->start_time)->addMinutes($shift->grace_minutes);
            // abs(): Carbon 3 diffs come back signed, and the greaterThan
            // guard above is what decides the sign — the magnitude here must
            // never carry one of its own.
            $late = $firstIn->greaterThan($deadline) ? $this->floorTo((int) abs($firstIn->diffInMinutes($deadline)), $settings['rounding']) : 0;
        }

        if ($shift !== null && $lastOut !== null) {
            $end = $this->atTime($day, $shift->end_time, $this->spansMidnight($shift));

            if ($lastOut->lessThan($end)) {
                $early = $this->floorTo((int) abs($end->diffInMinutes($lastOut)), $settings['rounding']);
            }
        }

        $rawOvertime = $net - $settings['ot_after'];
        $overtime = $rawOvertime > 0
            ? $this->floorTo($rawOvertime, $settings['rounding'])
            : ($settings['allow_negative_ot'] ? $rawOvertime : 0);

        return [
            'shift_id' => $shift?->id,
            'roster_id' => $roster?->id,
            'first_in_at' => $firstIn,
            'last_out_at' => $lastOut,
            'worked_minutes' => $net,
            'break_minutes' => $break,
            'late_by_minutes' => $late,
            'early_by_minutes' => $early,
            'overtime_minutes' => $overtime,
            'status' => $this->statusFor($firstIn, $lastOut, $net, $late, $settings)->value,
        ];
    }

    /**
     * The punches belonging to a date.
     *
     * A punch belongs to exactly one work date: its owning date (see
     * {@see owningDate}), decided by the shift active on the punch’s own
     * date. Day shifts own their whole calendar date; a night shift
     * additionally owns the next morning before noon, and yields its own
     * pre-noon hours to the previous night. The partition is what keeps two
     * overlapping night windows from pairing one punch into two days at
     * once — and what keeps a roster change overnight from orphaning the
     * dawn punch into neither.
     *
     * Filtering happens in PHP rather than SQL so the rule reads identically
     * on both grammars; a day holds a handful of punches, so the roster
     * lookups (cached per date) cost nothing next to the pairing itself.
     *
     * @return Collection<int, AttendancePunch>
     */
    private function punchesFor(Employee $employee, Carbon $day, ?AttendanceShift $shift): Collection
    {
        $tz = $this->tenantTimezone();
        $date = $day->copy()->tz($tz)->toDateString();
        $start = $day->copy()->tz($tz)->startOfDay()->utc();
        $end = $day->copy()->tz($tz)->addDay()->setTime(12, 0)->utc();

        $punches = AttendancePunch::where('employee_id', $employee->id)
            ->whereBetween('punch_at', [$start, $end])
            ->orderBy('punch_at')
            ->get();

        $shifts = [];

        return $punches->filter(function (AttendancePunch $punch) use ($employee, $tz, $date, &$shifts): bool {
            $punchDate = $punch->punch_at->copy()->tz($tz)->toDateString();

            $shifts[$punchDate] ??= $this->resolveShift($employee, Carbon::parse($punchDate, $tz))[0];

            return $this->owningDate($punch->punch_at, $shifts[$punchDate])->tz($tz)->toDateString() === $date;
        })->values();
    }

    /**
     * Which work date owns a punch: its own calendar date, unless the shift
     * active that date spans midnight and the punch fell before noon — then
     * the previous day, whose night it closed. Noon is arbitrary but fixed
     * and documented: a punch at exactly noon belongs to its own date.
     */
    public function owningDate(Carbon $at, ?AttendanceShift $shift): Carbon
    {
        $tz = $this->tenantTimezone();
        $local = $at->copy()->tz($tz);

        if ($shift !== null && $this->spansMidnight($shift) && (int) $local->format('H') < 12) {
            return $local->copy()->subDay()->startOfDay();
        }

        return $local->copy()->startOfDay();
    }

    /**
     * Pair in→out in order. Stray outs (no open in) are ignored, consecutive
     * ins keep the earliest open one, and a trailing open in contributes no
     * minutes — an unclosed session is time nobody can verify, and crediting
     * it would pay for hours that may never have happened.
     *
     * @return array{Carbon|null, Carbon|null, int}
     */
    private function pair(Collection $punches): array
    {
        $firstIn = null;
        $lastOut = null;
        $worked = 0;
        $open = null;

        foreach ($punches as $punch) {
            if ($punch->direction === PunchDirection::In) {
                $firstIn ??= $punch->punch_at;
                $open ??= $punch->punch_at;

                continue;
            }

            $lastOut = $punch->punch_at;

            if ($open !== null) {
                // abs() and int: Carbon 3 diffs are signed floats, and an
                // out-minus-in read backwards is a negative workday — the
                // same trap the work-log duration and the tenure math both
                // learned to bound.
                $worked += (int) abs($punch->punch_at->diffInMinutes($open));
                $open = null;
            }
        }

        return [$firstIn, $lastOut, $worked];
    }

    private function statusFor(?Carbon $firstIn, ?Carbon $lastOut, int $net, int $late, array $settings): AttendanceDayStatus
    {
        if ($firstIn === null && $lastOut === null) {
            return AttendanceDayStatus::Absent;
        }

        if ($net >= $settings['full_day']) {
            return $late > 0 ? AttendanceDayStatus::Late : AttendanceDayStatus::Present;
        }

        // Any punches at all with fewer minutes than a half day still counts
        // as a half day: the person showed up, and `absent` alongside punch
        // rows would be two answers to one question.
        return AttendanceDayStatus::HalfDay;
    }

    private function spansMidnight(AttendanceShift $shift): bool
    {
        return $shift->is_night || $shift->end_time <= $shift->start_time;
    }

    private function atTime(Carbon $day, string $time, bool $nextDay = false): Carbon
    {
        $at = $day->copy()->tz($this->tenantTimezone())->setTimeFromTimeString(substr($time, 0, 5));

        return $nextDay ? $at->addDay() : $at;
    }

    private function floorTo(int $minutes, int $step): int
    {
        return $step <= 1 ? $minutes : (int) floor($minutes / $step) * $step;
    }

    /**
     * @return array{rounding: int, ot_after: int, half_day: int, full_day: int, allow_negative_ot: bool}
     */
    private function thresholds(): array
    {
        $settings = HrmsSetting::query()->find(HrmsSetting::SINGLETON_ID);

        return [
            'rounding' => (int) ($settings?->setting('attendance.rounding_minutes') ?? 15),
            'ot_after' => (int) ($settings?->setting('attendance.ot_after_minutes') ?? 480),
            'half_day' => (int) ($settings?->setting('attendance.half_day_minutes') ?? 240),
            'full_day' => (int) ($settings?->setting('attendance.full_day_minutes') ?? 480),
            'allow_negative_ot' => (bool) ($settings?->setting('attendance.allow_negative_ot') ?? false),
        ];
    }

    /**
     * The wall clock the whole computation runs on: the tenant’s configured
     * zone, falling back to the application zone. Public because the punch
     * admission rules need the same clock — two clocks is how a punch lands
     * inside the window on one side and outside it on the other.
     */
    public function tenantTimezone(): string
    {
        return HrmsSetting::query()->find(HrmsSetting::SINGLETON_ID)?->timezone
            ?? config('app.timezone', 'UTC');
    }
}
