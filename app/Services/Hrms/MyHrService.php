<?php

namespace App\Services\Hrms;

use App\Models\Hrms\Asset\AssetAssignment;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Expense\ExpenseClaim;
use App\Models\Hrms\Leave\LeaveBalance;
use App\Models\Hrms\Leave\LeaveRequest;
use App\Models\Hrms\Lifecycle\DocumentRequest;
use App\Models\Hrms\Lifecycle\OffboardingCaseTask;
use App\Models\Hrms\Lifecycle\OnboardingCaseTask;
use App\Models\Hrms\Payroll\PayrollRun;
use App\Models\Hrms\Payroll\Payslip;
use App\Models\Hrms\Performance\CheckIn;
use App\Models\Hrms\Performance\OneOnOne;
use App\Models\Hrms\Performance\PerformanceGoal;
use App\Models\User;
use App\Models\UserSettings;
use App\Services\Hrms\Asset\AssetAssignmentService;
use App\Services\Hrms\Leave\LeaveCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * My/HRMS — one request for the employee home screen.
 *
 * Twelve small reads, no N+1 (relations eager-loaded per section, and the
 * test pins the query count): profile, leave balances plus upcoming
 * approved leave, the attendance month, pending request queues, the inbox
 * count, open hardware, expiring files, the latest payslip, active goals
 * with the latest check-in and next 1:1, open case items, and the static
 * quick-action catalog. Cached per login for 60 seconds — a minute-stale
 * home is the documented freshness contract, not per-write busting, which
 * no single seam could cover across twelve writers.
 */
class MyHrService
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly LeaveCalendar $calendar,
        private readonly InboxService $inbox,
        private readonly AssetAssignmentService $assets,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function aggregate(User $user): array
    {
        return Cache::remember("hrms.myhr.{$user->id}", 60, function () use ($user): array {
            $employee = Employee::where('user_id', $user->id)->first();

            if ($employee === null) {
                return ['profile' => null, 'sections' => [], 'quick_actions' => []];
            }

            $employee->loadMissing(['department:id,name', 'manager:id,name']);

            return [
                'profile' => $this->profile($employee),
                'sections' => [
                    'leave' => $this->leave($employee),
                    'attendance' => $this->attendance($employee),
                    'requests' => $this->requests($employee),
                    'inbox_count' => $this->inbox->unreadCount($user),
                    'assets' => $this->assets($employee),
                    'documents' => $this->documents($employee),
                    'payroll' => $this->payroll($employee),
                    'performance' => $this->performance($employee),
                    'cases' => $this->cases($employee),
                ],
                'quick_actions' => $this->quickActions(),
            ];
        });
    }

    /**
     * The notification preferences: stored under `user_settings.settings`
     * `['hrms']`, read back merged over the defaults so a new key defaults
     * on for existing rows without a backfill. Keys are the taxonomy the
     * digests read — adding a digest means adding a key here first.
     *
     * @return array<string, bool>
     */
    public function preferences(User $user): array
    {
        $stored = UserSettings::query()->where('user_id', $user->id)->value('settings') ?? [];

        return array_merge(self::preferenceDefaults(), (array) ($stored['hrms'] ?? []));
    }

    /**
     * @param  array<string, bool>  $preferences
     * @return array<string, bool>
     */
    public function savePreferences(User $user, array $preferences): array
    {
        $settings = UserSettings::query()->firstOrCreate(['user_id' => $user->id]);
        $all = $settings->settings ?? [];

        $all['hrms'] = array_merge(self::preferenceDefaults(), array_intersect_key($preferences, self::preferenceDefaults()));
        $settings->update(['settings' => $all]);

        Cache::forget("hrms.myhr.{$user->id}");

        return $this->preferences($user);
    }

    /**
     * @return array<string, bool>
     */
    private static function preferenceDefaults(): array
    {
        return [
            'email_digest' => true,
            'inbox_badge' => true,
            'attendance_reminders' => true,
            'leave_reminders' => true,
            'payroll_published_alerts' => true,
            'document_expiry_alerts' => true,
            'weekly_summary' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'employee_code' => $employee->employee_code,
            'name' => $employee->displayName(),
            'designation' => $employee->designation,
            'department' => $employee->department?->name,
            'manager' => $employee->manager?->name,
            'status' => $employee->status->value,
            'joining_date' => $employee->joining_date?->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function leave(Employee $employee): array
    {
        $year = $this->calendar->leaveYearFor(today());

        $balances = LeaveBalance::query()->where('employee_id', $employee->id)
            ->where('year', $year)
            ->with('type:id,name')
            ->get()
            ->map(fn (LeaveBalance $balance): array => [
                'type' => $balance->type?->name,
                'balance' => (float) $balance->balance,
            ])->all();

        $upcoming = LeaveRequest::query()->where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('to_date', '>=', today()->toDateString())
            ->with('type:id,name')
            ->orderBy('from_date')
            ->limit(3)
            ->get()
            ->map(fn (LeaveRequest $ask): array => [
                'id' => $ask->id,
                'type' => $ask->type?->name,
                'from_date' => $ask->from_date->toDateString(),
                'to_date' => $ask->to_date->toDateString(),
            ])->all();

        return ['balances' => $balances, 'upcoming' => $upcoming];
    }

    /**
     * @return array<string, mixed>
     */
    private function attendance(Employee $employee): array
    {
        $now = today();

        return [
            'month' => $this->attendance->month($employee, $now->year, $now->month)['summary'],
            'today' => $this->attendance->today($employee)['status']->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requests(Employee $employee): array
    {
        $leave = LeaveRequest::query()->where('employee_id', $employee->id)
            ->whereIn('status', ['pending', 'submitted'])
            ->orderByDesc('id')->limit(5)->get(['id', 'status', 'from_date', 'to_date']);

        $expenses = ExpenseClaim::query()->where('employee_id', $employee->id)
            ->whereIn('status', ['draft', 'submitted', 'pending'])
            ->orderByDesc('id')->limit(5)->get(['id', 'claim_number', 'status', 'total_amount']);

        return [
            'leave' => $leave->map(fn (LeaveRequest $ask): array => [
                'id' => $ask->id, 'status' => $ask->status->value,
                'from_date' => $ask->from_date->toDateString(), 'to_date' => $ask->to_date->toDateString(),
            ])->all(),
            'expenses' => $expenses->map(fn (ExpenseClaim $claim): array => [
                'id' => $claim->id, 'claim_number' => $claim->claim_number,
                'status' => $claim->status->value, 'total_amount' => (string) $claim->total_amount,
            ])->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function assets(Employee $employee): array
    {
        return $this->assets->byEmployee($employee)->map(fn (AssetAssignment $assignment): array => [
            'id' => $assignment->asset?->id,
            'asset_code' => $assignment->asset?->asset_code,
            'name' => $assignment->asset?->name,
            'acknowledged' => $assignment->acknowledged_at !== null,
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function documents(Employee $employee): array
    {
        $expiring = EmployeeDocument::query()->where('employee_id', $employee->id)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', today()->addDays(30)->toDateString())
            ->orderBy('expires_at')
            ->limit(5)
            ->get(['id', 'title', 'expires_at'])
            ->map(fn (EmployeeDocument $document): array => [
                'id' => $document->id,
                'title' => $document->title,
                'expires_at' => $document->expires_at->toDateString(),
            ])->all();

        $pending = DocumentRequest::query()->where('employee_id', $employee->id)
            ->outstanding()
            ->count();

        return ['expiring' => $expiring, 'pending_asks' => $pending];
    }

    /**
     * @return array<string, mixed>
     */
    private function payroll(Employee $employee): array
    {
        $latest = Payslip::query()->where('employee_id', $employee->id)
            ->with('run:id,period_year,period_month,pay_date,status')
            ->orderByDesc('id')
            ->first();

        $upcoming = PayrollRun::query()
            ->whereDate('pay_date', '>=', today()->toDateString())
            ->orderBy('pay_date')
            ->value('pay_date');

        return [
            'latest' => $latest === null ? null : [
                'id' => $latest->id,
                'period' => $latest->run === null ? null : "{$latest->run->period_year}-{$latest->run->period_month}",
                'net_pay' => (string) $latest->net_pay,
                'status' => $latest->status->value,
            ],
            'next_pay_date' => $upcoming === null ? null : Carbon::parse((string) $upcoming)->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function performance(Employee $employee): array
    {
        $goals = PerformanceGoal::query()->where('employee_id', $employee->id)
            ->whereIn('status', ['draft', 'active'])
            ->orderBy('id')
            ->get(['id', 'title', 'progress_percent', 'status']);

        $checkIn = CheckIn::query()->where('employee_id', $employee->id)
            ->orderByDesc('id')
            ->first(['id', 'body', 'mood', 'created_at']);

        $oneOnOne = OneOnOne::query()->where('employee_id', $employee->id)
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at')
            ->first(['id', 'scheduled_at', 'agenda']);

        return [
            'goals' => $goals->map(fn (PerformanceGoal $goal): array => [
                'id' => $goal->id, 'title' => $goal->title,
                'progress_percent' => (string) $goal->progress_percent, 'status' => $goal->status->value,
            ])->all(),
            'latest_check_in' => $checkIn === null ? null : [
                'id' => $checkIn->id,
                'body' => $checkIn->body,
                'mood' => $checkIn->mood?->value,
                'created_at' => $checkIn->created_at?->toIso8601String(),
            ],
            'next_one_on_one' => $oneOnOne === null ? null : [
                'id' => $oneOnOne->id,
                'scheduled_at' => $oneOnOne->scheduled_at?->toIso8601String(),
                'agenda' => $oneOnOne->agenda,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cases(Employee $employee): array
    {
        $rows = OnboardingCaseTask::query()->where('owner_employee_id', $employee->id)
            ->open()
            ->with('case:id')
            ->orderBy('due_date')->limit(10)->get()
            ->map(fn (OnboardingCaseTask $task): array => [
                'id' => $task->id, 'kind' => 'onboarding', 'title' => $task->title,
                'due_date' => $task->due_date?->toDateString(), 'status' => $task->status->value,
            ]);

        return $rows->concat(OffboardingCaseTask::query()->where('owner_employee_id', $employee->id)
            ->open()
            ->with('case:id')
            ->orderBy('due_date')->limit(10)->get()
            ->map(fn (OffboardingCaseTask $task): array => [
                'id' => $task->id, 'kind' => 'offboarding', 'title' => $task->title,
                'due_date' => $task->due_date?->toDateString(), 'status' => $task->status->value,
            ]))->all();
    }

    /**
     * @return list<array<string, string>>
     */
    private function quickActions(): array
    {
        return [
            ['key' => 'file_expense', 'label' => 'File an expense', 'href' => '/hrms/expenses/mine'],
            ['key' => 'request_leave', 'label' => 'Request leave', 'href' => '/hrms/leave/mine'],
            ['key' => 'my_payslips', 'label' => 'My payslips', 'href' => '/hrms/payroll/mine'],
            ['key' => 'my_inbox', 'label' => 'My inbox', 'href' => '/hrms/inbox'],
            ['key' => 'my_files', 'label' => 'My files', 'href' => '/hrms/documents/mine'],
        ];
    }
}
