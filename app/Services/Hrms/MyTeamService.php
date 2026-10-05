<?php

namespace App\Services\Hrms;

use App\Models\Hrms\Asset\AssetAssignment;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveBalance;
use App\Models\Hrms\Leave\LeaveRequest;
use App\Models\Hrms\Lifecycle\DocumentRequest;
use App\Models\Hrms\Payroll\Payslip;
use App\Models\Hrms\Performance\PerformanceGoal;
use App\Models\Hrms\Performance\ReviewSummary;
use App\Models\Hrms\Shared\Approval;
use App\Models\Task;
use App\Models\User;
use App\Services\Hrms\Leave\LeaveCalendar;
use Illuminate\Support\Carbon;

/**
 * Team/HRMS — the manager's read-only telescope.
 *
 * `team()` reads the viewer's direct reports only (manager_id, not the
 * transitive tree — a director's telescope points one level, and deeper
 * needs the org chart, not this endpoint). `employeeSummary()` is the HR
 * counterpart for one record: headlines from every module, identifiers
 * only, and never salary figures — pay stays behind `hrms.compensation.*`
 * no matter who asks.
 *
 * Auth lives in the controllers/policies; this answers shape. A login
 * without an employment record manages nobody and reads an empty team,
 * not a 403.
 */
class MyTeamService
{
    public function __construct(
        private readonly LeaveCalendar $calendar,
        private readonly AttendanceService $attendance,
    ) {}

    /**
     * One card per direct report: today's presence, the window summary,
     * leave-today, overdue work, their waiting approvals, and a compact
     * balance. No salary anywhere — assert it in the test, not just here.
     *
     * @return list<array<string, mixed>>
     */
    public function team(User $viewer, Carbon|string $from, Carbon|string $to): array
    {
        $me = Employee::where('user_id', $viewer->id)->first();

        if ($me === null) {
            return [];
        }

        $from = $from instanceof Carbon ? $from->toDateString() : (string) $from;
        $to = $to instanceof Carbon ? $to->toDateString() : (string) $to;

        return Employee::query()->where('manager_id', $me->id)
            ->with(['department:id,name'])
            ->orderBy('name')
            ->get()
            ->map(fn (Employee $report): array => $this->reportCard($report, $from, $to))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function reportCard(Employee $report, string $from, string $to): array
    {
        $today = today()->toDateString();

        $onLeave = LeaveRequest::query()->where('employee_id', $report->id)
            ->where('status', 'approved')
            ->whereDate('from_date', '<=', $today)
            ->whereDate('to_date', '>=', $today)
            ->exists();

        $overdue = Task::query()->where('assignee_id', $report->user_id)
            ->whereNull('completed_at')
            ->whereDate('due_date', '<', $today)
            ->orderBy('due_date')
            ->limit(5)
            ->get(['id', 'key', 'title', 'due_date', 'project_id']);

        $approvals = Approval::query()->where('requested_by_employee_id', $report->id)
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'subject', 'action']);

        return [
            'id' => $report->id,
            'employee_code' => $report->employee_code,
            'name' => $report->displayName(),
            'status' => $report->status->value,
            'department' => $report->department?->name,
            'today' => $this->attendance->today($report)['status']->value,
            'window' => $this->attendance->summary($report, $from, $to),
            'on_leave_today' => $onLeave,
            'overdue_tasks' => $overdue->map(fn (Task $task): array => [
                'id' => $task->id,
                'key' => $task->key,
                'title' => $task->title,
                'due_date' => $task->due_date->toDateString(),
            ])->all(),
            'pending_approvals' => $approvals->map(fn (Approval $approval): array => [
                'id' => $approval->id,
                'subject' => $approval->subject,
                'action' => $approval->action,
            ])->all(),
            'leave_balances' => $this->balances($report),
        ];
    }

    /**
     * One employee's cross-module headlines for HR: status and tenure,
     * org placement, and a number per module. Amounts never travel (no
     * CTC, no salary, no net pay) — headlines identify, they do not price.
     *
     * @return array<string, mixed>
     */
    public function employeeSummary(Employee $employee): array
    {
        $employee->loadMissing(['department:id,name', 'manager:id,name']);

        $year = $this->calendar->leaveYearFor(today());

        return [
            'id' => $employee->id,
            'employee_code' => $employee->employee_code,
            'name' => $employee->displayName(),
            'status' => $employee->status->value,
            'joining_date' => $employee->joining_date?->toDateString(),
            'tenure_years' => $employee->tenureOn(),
            'department' => $employee->department?->name,
            'manager' => $employee->manager?->name,
            'attendance' => $this->attendance->summary(
                $employee,
                today()->copy()->startOfMonth()->toDateString(),
                today()->toDateString(),
            ),
            'leave_balances' => $this->balances($employee, $year),
            'assets' => AssetAssignment::query()->where('employee_id', $employee->id)
                ->where('status', 'active')
                ->with('asset:id,asset_code,name')
                ->get()
                ->map(fn (AssetAssignment $assignment): array => [
                    'asset_code' => $assignment->asset?->asset_code,
                    'name' => $assignment->asset?->name,
                    'acknowledged' => $assignment->acknowledged_at !== null,
                ])->all(),
            'documents' => [
                'expiring_soon' => EmployeeDocument::query()->where('employee_id', $employee->id)
                    ->whereNotNull('expires_at')
                    ->whereDate('expires_at', '<=', today()->addDays(30)->toDateString())
                    ->count(),
                'pending_asks' => DocumentRequest::query()->where('employee_id', $employee->id)
                    ->outstanding()
                    ->count(),
            ],
            'performance' => [
                'active_goals' => PerformanceGoal::query()->where('employee_id', $employee->id)
                    ->whereIn('status', ['draft', 'active'])
                    ->count(),
                'open_reviews' => ReviewSummary::query()
                    ->where('employee_id', $employee->id)
                    ->whereIn('status', ['draft', 'calibrating'])
                    ->count(),
            ],
            'payroll' => $this->latestPayslip($employee),
        ];
    }

    /**
     * The latest payslip as identifiers only: run, id and status — never
     * figures. Headlines identify, they do not price.
     *
     * @return array<string, mixed>|null
     */
    private function latestPayslip(Employee $employee): ?array
    {
        $payslip = Payslip::query()->where('employee_id', $employee->id)
            ->orderByDesc('id')
            ->first();

        if ($payslip === null) {
            return null;
        }

        return [
            'id' => $payslip->id,
            'payroll_run_id' => $payslip->payroll_run_id,
            'status' => $payslip->status->value,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function balances(Employee $employee, ?int $year = null): array
    {
        $year ??= $this->calendar->leaveYearFor(today());

        return LeaveBalance::query()->where('employee_id', $employee->id)
            ->where('year', $year)
            ->with('type:id,name')
            ->get()
            ->map(fn (LeaveBalance $balance): array => [
                'type' => $balance->type?->name,
                'balance' => (float) $balance->balance,
            ])->all();
    }
}
