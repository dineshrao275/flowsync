<?php

namespace App\Http\Controllers\Hrms\Attendance;

use App\Enums\Hrms\PunchDirection;
use App\Enums\Hrms\PunchKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\Attendance\AttendancePunchRequest;
use App\Http\Requests\Hrms\Attendance\AttendanceSettingsRequest;
use App\Models\Hrms\Attendance\AttendancePunch;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Shared\HrmsSetting;
use App\Services\Hrms\Attendance\DayPresenter;
use App\Services\Hrms\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Attendance/HRMS — clock policy and the remote punch over HTTP.
 *
 * Thin by design: it resolves, hands the payload to the service, shapes the
 * response. Two endpoints with deliberately different gates — settings takes
 * `hrms.attendance.settings`, while the punch takes no permission at all:
 * the punch resolves the employment record from the authenticated user, so
 * there is nothing to authorize against and no login may punch for another.
 */
class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly DayPresenter $presenter,
    ) {}

    /**
     * Read the tenant's current attendance and remote clock-in policy.
     */
    public function getSettings(): JsonResponse
    {
        $settings = HrmsSetting::current();

        return response()->json([
            'settings' => [
                'attendance' => $settings->attendance,
                'remote_clock_in' => $settings->remote_clock_in,
            ],
        ]);
    }

    /**
     * Replace the provided policy sections, keeping the rest.
     *
     * Per-section merge, not whole-row replace: a client tuning the rounding
     * step must not have to resend the geofence policy to keep it, and a PUT
     * that blanked unmentioned sections would be a reset disguised as an
     * edit.
     */
    public function settings(AttendanceSettingsRequest $request): JsonResponse
    {
        $settings = HrmsSetting::current();
        $validated = $request->validated();

        foreach (['attendance', 'remote_clock_in'] as $section) {
            if (array_key_exists($section, $validated)) {
                $settings->{$section} = array_merge($settings->{$section} ?? [], $validated[$section]);
            }
        }

        $settings->save();

        return response()->json([
            'message' => 'Attendance settings saved.',
            'settings' => [
                'attendance' => $settings->attendance,
                'remote_clock_in' => $settings->remote_clock_in,
            ],
        ]);
    }

    /**
     * Clock in or out as yourself.
     *
     * The IP is server-observed (`$request->ip()`), never client-claimed: a
     * `meta.ip` field would let anyone assert they are at the office, which
     * defeats the only thing the IP leg checks. Coordinates must come from
     * the client — the server has no other way to know where a phone is —
     * and a punch without them simply skips the geofence leg.
     */
    public function punch(AttendancePunchRequest $request): JsonResponse
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();

        abort_if($employee === null, 404, 'There is no employment record for this login to punch with.');

        // The tenant can switch remote clock-in off; the toggle is meaningless otherwise.
        abort_if(HrmsSetting::current()->setting('remote_clock_in.enabled') === false, 403, 'Remote clock-in is switched off for this workspace.');

        $validated = $request->validated();

        $punch = $this->attendance->punch(
            $employee,
            $validated['direction'],
            $validated['source'] ?? 'web',
            [
                'punch_at' => $validated['punch_at'] ?? null,
                'kind' => $validated['kind'] ?? 'work',
                'lat' => isset($validated['lat']) ? (float) $validated['lat'] : null,
                'lng' => isset($validated['lng']) ? (float) $validated['lng'] : null,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent() ? mb_substr($request->userAgent(), 0, 500) : null,
                'device_id' => $validated['device_id'] ?? null,
                'note' => $validated['note'] ?? null,
            ],
            $request->user(),
        );

        $day = $this->attendance->dayForPunch($employee, $punch);

        return response()->json([
            'message' => $this->punchMessage($punch),
            'punch' => $this->presenter->punch($punch),
            'day' => $this->presenter->day($day),
        ], Response::HTTP_CREATED);
    }

    private function punchMessage(AttendancePunch $punch): string
    {
        if ($punch->kind === PunchKind::Break) {
            return $punch->direction === PunchDirection::Out ? 'Break started.' : 'Break ended.';
        }

        return $punch->direction === PunchDirection::In ? 'Clocked in.' : 'Clocked out.';
    }
}
