<?php

namespace App\Http\Controllers\Hrms;

use App\Http\Controllers\Concerns\DetectsPlatformUsers;
use App\Http\Controllers\Controller;
use App\Models\Hrms\Employee\Employee;
use App\Services\Hrms\MyHrService;
use App\Services\Hrms\MyTeamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * My/HRMS — the telescope endpoints.
 *
 * `team` reads the viewer's direct reports (empty for logins managing
 * nobody, never 403 — there is nothing to forbid, only nothing to show).
 * `summary` answers HR through the employee policy. Preferences read and
 * write the `hrms` key of the caller's user settings with unknown keys
 * dropped, so a client typo cannot invent a digest.
 */
class MyTeamController extends Controller
{
    use DetectsPlatformUsers;

    public function __construct(
        private readonly MyTeamService $team,
        private readonly MyHrService $home,
    ) {}

    public function team(Request $request): JsonResponse
    {
        abort_if($this->isPlatformSuperAdmin($request), 404);

        $filters = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $to = isset($filters['to']) ? $filters['to'] : today()->toDateString();
        $from = isset($filters['from']) ? $filters['from'] : today()->copy()->startOfMonth()->toDateString();

        return response()->json([
            'reports' => $this->team->team($request->user(), $from, $to),
        ]);
    }

    public function summary(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('view', $employee);

        return response()->json([
            'summary' => $this->team->employeeSummary($employee),
        ]);
    }

    public function preferences(Request $request): JsonResponse
    {
        abort_if($this->isPlatformSuperAdmin($request), 404);

        return response()->json([
            'preferences' => $this->home->preferences($request->user()),
        ]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        abort_if($this->isPlatformSuperAdmin($request), 404);

        $validated = $request->validate([
            'email_digest' => ['sometimes', 'boolean'],
            'inbox_badge' => ['sometimes', 'boolean'],
            'attendance_reminders' => ['sometimes', 'boolean'],
            'leave_reminders' => ['sometimes', 'boolean'],
            'payroll_published_alerts' => ['sometimes', 'boolean'],
            'document_expiry_alerts' => ['sometimes', 'boolean'],
            'weekly_summary' => ['sometimes', 'boolean'],
        ]);

        return response()->json([
            'preferences' => $this->home->savePreferences($request->user(), $validated),
        ]);
    }
}
