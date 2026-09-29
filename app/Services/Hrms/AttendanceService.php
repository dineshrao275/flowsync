<?php

namespace App\Services\Hrms;

use App\Enums\Hrms\AttendanceDayStatus;
use App\Enums\Hrms\PunchDirection;
use App\Enums\Hrms\PunchSource;
use App\Models\Hrms\Attendance\AttendanceDay;
use App\Models\Hrms\Attendance\AttendancePunch;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use App\Services\Hrms\Attendance\DayComputation;
use App\Services\Hrms\Attendance\DayReading;
use App\Services\Hrms\Attendance\PunchClock;
use Illuminate\Support\Carbon;

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
     * @return array{days: int, present: int, absent: int, half_day: int, late: int, leave: int, holiday: int, week_off: int, worked_minutes: int, late_minutes: int, overtime_minutes: int}
     */
    public function summary(Employee $employee, Carbon|string $from, Carbon|string $to): array
    {
        return $this->reading->summary($employee, $from, $to);
    }
}
