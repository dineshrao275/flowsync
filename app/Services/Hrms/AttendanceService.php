<?php

namespace App\Services\Hrms;

use App\Enums\Hrms\AttendanceDayStatus;
use App\Enums\Hrms\PunchDirection;
use App\Enums\Hrms\PunchSource;
use App\Models\Hrms\Attendance\AttendanceDay;
use App\Models\Hrms\Attendance\AttendancePunch;
use App\Models\Hrms\Attendance\AttendanceRoster;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use App\Services\Hrms\Attendance\DayComputation;
use App\Services\Hrms\Attendance\DayReading;
use App\Services\Hrms\Attendance\PunchClock;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Attendance/HRMS — clock events in, day photographs out.
 *
 * A thin orchestrator on purpose. Admission rules (window, duplicates,
 * network, geofence) live in {@see PunchClock}; pairing, rounding and shift
 * resolution live in {@see DayComputation}. A controller, a command or an
 * import worker therefore cannot reimplement one rule and drift from the
 * others.
 */
class AttendanceService
{
    public function __construct(
        private readonly PunchClock $clock,
        private readonly DayComputation $days,
        private readonly DayReading $reading,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array{punch_at?: Carbon|string|null, lat?: float|null, lng?: float|null, ip?: string|null, user_agent?: string|null, device_id?: string|null, location_id?: int|null, note?: string|null}  $meta
     */
    public function punch(
        Employee $employee,
        PunchDirection|string $direction,
        PunchSource|string $source = PunchSource::Web,
        array $meta = [],
        ?User $actor = null,
    ): AttendancePunch {
        return $this->clock->punch($employee, $direction, $source, $meta, $actor);
    }

    /**
     * The day a punch belongs to, freshly computed.
     *
     * The owning date, not the punch date: a night-shift out-punch lands the
     * next morning, and the morning’s row is not the day the night belongs
     * to. Recompute-on-read is idempotent, so this is a fresh photograph
     * rather than a second implementation of one.
     */
    public function dayForPunch(Employee $employee, AttendancePunch $punch): AttendanceDay
    {
        [$shift] = $this->days->resolveShift($employee, $punch->punch_at->copy()->startOfDay());

        return $this->days->computeDay($employee, $this->days->owningDate($punch->punch_at, $shift));
    }

    public function computeDay(Employee $employee, Carbon|string $date): AttendanceDay
    {
        return $this->days->computeDay($employee, $date);
    }

    /**
     * Recompute after an external change — a roster edit, a regularization.
     * Alias by design, not by accident: recomputing is the same idempotent
     * operation wherever it is triggered from, and two names for it would
     * diverge the moment one of them grows a special case.
     */
    public function regenerate(Employee $employee, Carbon|string $date): AttendanceDay
    {
        return $this->days->computeDay($employee, $date);
    }

    /**
     * Apply an approved regularization: superseding punches plus a recompute.
     *
     * The only writer of `regularized`-source punches besides a future
     * import: corrections insert, never edit, so the day row always equals
     * its inputs. Both timestamps are expected on the date being corrected —
     * the regularization service validates that before calling.
     */
    public function applyRegularization(
        Employee $employee,
        Carbon|string $date,
        Carbon|string|null $firstIn,
        Carbon|string|null $lastOut,
        ?User $decider = null,
    ): AttendanceDay {
        foreach ([[$firstIn, PunchDirection::In], [$lastOut, PunchDirection::Out]] as [$at, $direction]) {
            if ($at === null) {
                continue;
            }

            AttendancePunch::create([
                'employee_id' => $employee->id,
                'punch_at' => $at,
                'direction' => $direction->value,
                'source' => PunchSource::Regularized->value,
                'is_out_of_range' => false,
                'created_by' => $decider?->id,
            ]);
        }

        $day = $this->regenerate($employee, $date);
        $day->update(['is_regularized' => true, 'regularized_by_user_id' => $decider?->id]);

        return $day->refresh();
    }

    public function dayStatus(Employee $employee, Carbon|string $date): AttendanceDayStatus
    {
        return $this->reading->dayStatus($employee, $date);
    }

    /**
     * Whether a date is the employee's weekly off.
     *
     * The Leave context's day split reads the roster through here, never the
     * roster table (D2.16.2) — the only cross-context edge this feature
     * needs, and it points one way.
     */
    public function isWeekOff(Employee $employee, Carbon|string $date): bool
    {
        return $this->reading->isWeekOff($employee, $date);
    }

    /**
     * Whether any punch was recorded for a date.
     *
     * The comp-off accrual's skip rule: worked time stays in attendance and
     * payroll, so a punched date never banks rest time on top.
     */
    public function hasPunches(Employee $employee, Carbon|string $date): bool
    {
        return $this->reading->hasPunches($employee, $date);
    }

    /**
     * Rostered week-offs across a window, computed from preloaded rosters
     * in PHP — one query for the window, never per date. The payroll
     * engine's working-day math reads through here instead of resolving
     * a roster per date per employee.
     *
     * @return list<string> Y-m-d dates
     */
    public function weeklyOffDates(Employee $employee, Carbon|string $from, Carbon|string $to): array
    {
        $from = $from instanceof Carbon ? $from->copy()->startOfDay() : Carbon::parse((string) $from)->startOfDay();
        $to = $to instanceof Carbon ? $to->copy()->startOfDay() : Carbon::parse((string) $to)->startOfDay();

        $rosters = AttendanceRoster::where('employee_id', $employee->id)
            ->whereDate('effective_from', '<=', $to->toDateString())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from->toDateString()))
            ->orderBy('effective_from')
            ->get();

        $days = [];

        for ($date = $from->copy(); $date->lessThanOrEqualTo($to); $date->addDay()) {
            $day = $date->toDateString();

            $roster = $rosters
                ->filter(fn ($row): bool => $row->effective_from->toDateString() <= $day
                    && ($row->effective_to === null || $row->effective_to->toDateString() >= $day))
                ->last();

            $offs = $roster?->weekly_offs;

            if (is_array($offs) && ($offs[$date->copy()->tz($this->days->tenantTimezone())->dayOfWeekIso - 1] ?? false)) {
                $days[] = $day;
            }
        }

        return $days;
    }

    /**
     * Unexplained presence gaps across a window: punch-less day rows by
     * status, plus worked overtime minutes. The payroll engine subtracts
     * approved leave itself — this reports the photograph, not the verdict.
     *
     * @return array{absent: list<string>, half: list<string>, overtime_minutes: int}
     */
    public function absenceDetail(Employee $employee, Carbon|string $from, Carbon|string $to): array
    {
        $from = $from instanceof Carbon ? $from->toDateString() : (string) $from;
        $to = $to instanceof Carbon ? $to->toDateString() : (string) $to;

        $rows = AttendanceDay::where('employee_id', $employee->id)
            ->whereDate('work_date', '>=', $from)
            ->whereDate('work_date', '<=', $to)
            ->get();

        $absent = [];
        $half = [];
        $overtime = 0;

        foreach ($rows as $row) {
            $punched = $row->first_in_at !== null || $row->last_out_at !== null;

            if (! $punched && $row->status->value === 'absent') {
                $absent[] = $row->work_date->toDateString();
            }

            if (! $punched && $row->status->value === 'half_day') {
                $half[] = $row->work_date->toDateString();
            }

            $overtime += $row->overtime_minutes;
        }

        return ['absent' => $absent, 'half' => $half, 'overtime_minutes' => $overtime];
    }

    /**
     * @return array{year: int, month: int, days: list<array{date: string, status: AttendanceDayStatus, record: AttendanceDay|null}>, summary: array{days: int, present: int, absent: int, half_day: int, late: int, leave: int, holiday: int, week_off: int, worked_minutes: int, late_minutes: int, overtime_minutes: int}}
     */
    public function month(Employee $employee, int $year, int $month): array
    {
        return $this->reading->month($employee, $year, $month);
    }

    /**
     * @return array{date: string, status: AttendanceDayStatus, record: AttendanceDay|null, punches: Collection<int, AttendancePunch>}
     */
    public function today(Employee $employee): array
    {
        return $this->reading->today($employee);
    }

    /**
     * @return array{days: int, present: int, absent: int, half_day: int, late: int, leave: int, holiday: int, week_off: int, worked_minutes: int, late_minutes: int, overtime_minutes: int}
     */
    public function summary(Employee $employee, Carbon|string $from, Carbon|string $to): array
    {
        return $this->reading->summary($employee, $from, $to);
    }

    /**
     * Ensure every active employee owns a day row for the date.
     *
     * The nightly close: `computeDay` is idempotent, so a re-run only
     * re-photographs — punches that arrived after the first pass correct the
     * row instead of duplicating it, and a person with no punches (and no
     * leave/holiday merge yet — those arrive via P6/P7 regeneration) reads
     * `absent`. No audit rows: this is idempotent maintenance, not a
     * decision anyone took, and a ledger row per employee per night would
     * bury the decisions. The operational line below is the trail.
     *
     * @return array{employees: int, ensured: int, duration_ms: int}
     */
    public function rollup(Carbon|string $date): array
    {
        $started = microtime(true);
        $ensured = 0;

        $employees = Employee::query()->active()->orderBy('id')->get();

        foreach ($employees as $employee) {
            $this->computeDay($employee, $date);
            $ensured++;
        }

        $result = [
            'employees' => $employees->count(),
            'ensured' => $ensured,
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
        ];

        Log::channel('hrms')->info('attendance.rollup.completed', [
            'tenant_id' => $this->context->currentId(),
            'work_date' => $date instanceof Carbon ? $date->toDateString() : (string) $date,
            ...$result,
        ]);

        return $result;
    }
}
