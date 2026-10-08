<?php

namespace App\Services\Hrms\Attendance;

use App\Enums\Hrms\PunchDirection;
use App\Enums\Hrms\PunchSource;
use App\Models\Hrms\Attendance\AttendanceIpRule;
use App\Models\Hrms\Attendance\AttendancePunch;
use App\Models\Hrms\Attendance\AttendanceShift;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Org\Location;
use App\Models\User;
use App\Services\Hrms\AttendanceService;
use App\Services\HrmsAuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Attendance/HRMS — recording one clock event.
 *
 * Split from {@see AttendanceService} because the
 * admission rules (window, duplicates, network, geofence) are a
 * self-contained decision: what may be recorded, and what gets flagged
 * while being recorded. The day math lives in {@see DayComputation}, which
 * this calls after every accepted punch so the photograph never lags the
 * events behind it.
 *
 * Out-of-range is a flag on the row, never a refusal. A mispinned geofence
 * or a stale IP allow-list must not lock a person out of recording that
 * they worked — the reviewer reads the flag and the reason instead.
 */
class PunchClock
{
    /**
     * Two punches, same direction, this close together are one press of the
     * button recorded twice — a double-tap, a retried request — not two
     * work sessions five minutes apart.
     */
    private const DUPLICATE_WINDOW_MINUTES = 5;

    public function __construct(
        private readonly DayComputation $days,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /**
     * Record a punch and recompute its day.
     *
     * @param  array{punch_at?: Carbon|string|null, lat?: float|null, lng?: float|null, ip?: string|null, user_agent?: string|null, device_id?: string|null, location_id?: int|null, note?: string|null}  $meta
     *
     * @throws ValidationException outside the shift window or on a duplicate
     */
    public function punch(
        Employee $employee,
        PunchDirection|string $direction,
        PunchSource|string $source = PunchSource::Web,
        array $meta = [],
        ?User $actor = null,
    ): AttendancePunch {
        $direction = $direction instanceof PunchDirection ? $direction : PunchDirection::tryFrom((string) $direction);
        $source = $source instanceof PunchSource ? $source : PunchSource::tryFrom((string) $source);

        if ($direction === null) {
            throw ValidationException::withMessages(['direction' => 'A punch goes in or out.']);
        }

        if ($source === null) {
            throw ValidationException::withMessages(['source' => 'That is not a way a punch arrives.']);
        }

        $at = isset($meta['punch_at']) && $meta['punch_at'] !== null
            ? Carbon::parse((string) $meta['punch_at'])
            : now();

        [$shift] = $this->days->resolveShift($employee, $at->copy()->startOfDay());
        $this->requireInWindow($employee, $shift, $at, $direction);
        $this->requireNotDuplicate($employee, $direction, $at);

        return DB::transaction(function () use ($employee, $direction, $source, $meta, $actor, $at, $shift): AttendancePunch {
            [$flagged, $reason] = $this->rangeCheck($employee, $meta);

            $punch = AttendancePunch::create([
                'employee_id' => $employee->id,
                'punch_at' => $at,
                'direction' => $direction->value,
                'source' => $source->value,
                'lat' => $meta['lat'] ?? null,
                'lng' => $meta['lng'] ?? null,
                'ip' => $meta['ip'] ?? null,
                'user_agent' => $meta['user_agent'] ?? null,
                'device_id' => $meta['device_id'] ?? null,
                'location_id' => $meta['location_id'] ?? null,
                'is_out_of_range' => $flagged,
                'out_of_range_reason' => $reason,
                'note' => $meta['note'] ?? null,
                'created_by' => $actor?->id,
            ]);

            $this->audit->log($punch, 'attendance.punched', null, [
                'employee_id' => $employee->id,
                'direction' => $direction->value,
                'source' => $source->value,
            ], $actor);

            // The punch’s own date, plus its owning date when they differ: a
            // night-shift out-punch belongs to the night it closed, and the
            // night’s row was last computed when only the in-punch existed.
            // Both recomputes are idempotent, so the extra one on shared
            // dates costs a query and buys a day that never goes stale.
            $owning = $this->days->owningDate($at, $shift);
            $this->days->computeDay($employee, $owning);

            $punchDay = $at->copy()->tz($this->days->tenantTimezone())->toDateString();

            if ($owning->copy()->tz($this->days->tenantTimezone())->toDateString() !== $punchDay) {
                $this->days->computeDay($employee, $at->copy()->startOfDay());
            }

            return $punch->refresh();
        });
    }

    /**
     * The punch must fall inside the shift window, expanded by grace — but
     * asymmetrically: an in-punch is bounded on both sides, an out-punch
     * only from below. Clocking out after the shift ends is overtime, the
     * most ordinary thing in attendance, and an upper bound on it would make
     * every late evening a 422. An out-punch before the shift starts is
     * refused instead: with no session open yet, there is nothing to close.
     *
     * @throws ValidationException outside the window
     */
    private function requireInWindow(Employee $employee, ?AttendanceShift $shift, Carbon $at, PunchDirection $direction): void
    {
        if ($shift === null) {
            return;
        }

        $tz = $at->copy()->tz($this->days->tenantTimezone());
        $minutes = ((int) $tz->format('H')) * 60 + ((int) $tz->format('i'));
        [$start, $end] = $this->windowMinutes($shift);

        $inside = $end < $start
            ? $minutes >= $start || $minutes <= $end
            : ($direction === PunchDirection::In
                ? $minutes >= $start && $minutes <= $end
                : $minutes >= $start);

        if (! $inside) {
            throw ValidationException::withMessages(['punch_at' => "This {$direction->value} falls outside {$shift->name}’s hours, even with grace."]);
        }
    }

    /**
     * @return array{int, int} Window start/end in minutes past midnight.
     */
    private function windowMinutes(AttendanceShift $shift): array
    {
        $start = $this->toMinutes($shift->start_time) - $shift->grace_minutes;
        $end = $this->toMinutes($shift->end_time) + $shift->grace_minutes;

        return [$start, $end];
    }

    private function toMinutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', substr($time, 0, 5)));

        return $hour * 60 + $minute;
    }

    private function requireNotDuplicate(Employee $employee, PunchDirection $direction, Carbon $at): void
    {
        $tooClose = AttendancePunch::where('employee_id', $employee->id)
            ->where('direction', $direction->value)
            ->whereBetween('punch_at', [
                $at->copy()->subMinutes(self::DUPLICATE_WINDOW_MINUTES),
                $at->copy()->addMinutes(self::DUPLICATE_WINDOW_MINUTES),
            ])
            ->exists();

        if ($tooClose) {
            throw ValidationException::withMessages(['punch_at' => 'This looks like the same press recorded twice.']);
        }
    }

    /**
     * IP rules, then the geofence. Either may flag; neither may refuse.
     *
     * @param  array<string, mixed>  $meta
     * @return array{bool, string|null}
     */
    private function rangeCheck(Employee $employee, array $meta): array
    {
        if (! empty($meta['ip']) && ($reason = $this->networkReason((string) $meta['ip'])) !== null) {
            return [true, $reason];
        }

        if (isset($meta['lat'], $meta['lng']) && ($reason = $this->fenceReason($employee, (float) $meta['lat'], (float) $meta['lng'])) !== null) {
            return [true, $reason];
        }

        return [false, null];
    }

    /**
     * An empty active set means “no network policy”, not “deny everything”:
     * a tenant that never configured IP rules must not find clock-in broken
     * on Monday because of a feature they never switched on.
     */
    private function networkReason(string $ip): ?string
    {
        $rules = AttendanceIpRule::active()->pluck('cidr')->all();

        if ($rules === []) {
            return null;
        }

        foreach ($rules as $cidr) {
            if (IpUtils::checkIp($ip, $cidr)) {
                return null;
            }
        }

        return "IP {$ip} is outside the allowed networks.";
    }

    /**
     * Haversine metres against the employee’s fenced location, when there is
     * one and the punch carries coordinates. A punch with no coordinates
     * cannot be placed, so it is not flagged — absence of evidence, switched
     * off by the same logic that refuses to block on a maybe.
     */
    private function fenceReason(Employee $employee, float $lat, float $lng): ?string
    {
        $location = $employee->location_id !== null
            ? Location::find($employee->location_id)
            : null;

        if ($location === null || ! $location->isGeoFenced()) {
            return null;
        }

        $distance = $this->haversineMetres($lat, $lng, (float) $location->geo_lat, (float) $location->geo_lng);

        if ($distance <= (int) $location->geo_radius_m) {
            return null;
        }

        return sprintf('%.0f m outside %s’s %d m fence.', $distance, $location->name, $location->geo_radius_m);
    }

    private function haversineMetres(float $latA, float $lngA, float $latB, float $lngB): float
    {
        $earth = 6371000;
        $dLat = deg2rad($latB - $latA);
        $dLng = deg2rad($lngB - $lngA);

        $arc = sin($dLat / 2) ** 2
            + cos(deg2rad($latA)) * cos(deg2rad($latB)) * sin($dLng / 2) ** 2;

        return 2 * $earth * asin(sqrt($arc));
    }
}
