<?php

namespace App\Services\Hrms;

use App\Enums\Hrms\CaseTaskStatus;
use App\Models\Hrms\Asset\AssetAssignment;
use App\Models\Hrms\Attendance\AttendanceRegularizationRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Inbox\InboxRead;
use App\Models\Hrms\Lifecycle\DocumentRequest;
use App\Models\Hrms\Lifecycle\OffboardingCaseTask;
use App\Models\Hrms\Lifecycle\OnboardingCaseTask;
use App\Models\Hrms\Payroll\Payslip;
use App\Models\User;
use App\Services\Hrms\Employee\ReportingLine;
use App\Services\Hrms\Shared\ApprovalService;
use App\Support\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Inbox/HRMS — one queue from every pending thing, no queue table.
 *
 * Each source contributes items shaped `{key, type, title, subtitle,
 * priority, due_at, meta, source_id}`; the server sends `meta` (ids the
 * deep link needs), never a URL — the client builds `href`. Reads persist
 * per (login, key) in `inbox_reads`; anything unread is whatever the live
 * sources return minus those keys, counted under a 30s cache that every
 * mark call busts.
 *
 * Two deliberate extensions of the plan's sketch, both documented where
 * they happen: onboarding and offboarding asks ride distinct key prefixes
 * (one autoincrement shared by two tables would mark both read at once),
 * and undated pending items are included (no deadline is not no duty).
 * A non-impersonating super admin reads an empty queue — platform work
 * lives elsewhere, matching the notifications short-circuit.
 */
class InboxService
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly ReportingLine $reporting,
        private readonly DocumentService $documents,
        private readonly TenantContext $context,
    ) {}

    /**
     * The merged queue, high priority first, then earliest due (dateless
     * last), then key for stability.
     */
    public function items(User $user, int $page = 1, int $perPage = 20, int $dueWithinDays = 14): LengthAwarePaginator
    {
        $all = $this->all($user, $dueWithinDays)
            ->sortBy(fn (array $item): string => sprintf(
                '%d|%s|%s',
                $item['priority'] === 'high' ? 0 : 1,
                $item['due_at'] ?? '9999-12-31',
                $item['key'],
            ))
            ->values();

        return new Paginator(
            $all->forPage($page, $perPage)->values(),
            $all->count(),
            $perPage,
            $page,
        );
    }

    public function unreadCount(User $user): int
    {
        return (int) Cache::remember(
            "hrms.inbox.unread.{$user->id}",
            30,
            fn (): int => $this->unreadKeys($user)->count(),
        );
    }

    /**
     * @param  list<string>  $keys
     */
    public function markRead(User $user, array $keys): int
    {
        $marked = 0;

        foreach (array_unique(array_filter(array_map('strval', $keys))) as $key) {
            InboxRead::query()->updateOrCreate(
                ['user_id' => $user->id, 'item_key' => mb_substr($key, 0, 120)],
                ['read_at' => now()],
            );
            $marked++;
        }

        Cache::forget("hrms.inbox.unread.{$user->id}");

        return $marked;
    }

    public function markAllRead(User $user): int
    {
        return $this->markRead($user, $this->unreadKeys($user)->all());
    }

    /**
     * @return Collection<int, string>
     */
    private function unreadKeys(User $user): Collection
    {
        $read = InboxRead::query()->where('user_id', $user->id)->pluck('item_key');

        return $this->all($user)->pluck('key')->diff($read)->values();
    }

    /**
     * Every source, unpaginated and unsorted: approvals waiting on the
     * login, case items owned by them, their reports, or their HR pool,
     * their own corrections and file asks, their unsigned hardware, their
     * reports' expiring files, and their own disputed payslips.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function all(User $user, int $dueWithinDays = 14): Collection
    {
        if ($user->is_super_admin && ! $this->context->hasTenant()) {
            return collect();
        }

        $employeeId = Employee::where('user_id', $user->id)->value('id');
        $reportIds = $employeeId === null
            ? []
            : array_map(fn (Employee $report): int => $report->id, $this->reporting->reportsOf(Employee::find($employeeId)));

        return collect()
            ->concat($this->approvalItems($user))
            ->concat($this->caseTaskItems($user, $employeeId, $reportIds, $dueWithinDays))
            ->concat($this->regularizationItems($employeeId, $reportIds))
            ->concat($this->documentAskItems($employeeId))
            ->concat($this->assetItems($employeeId))
            ->concat($this->expiringDocumentItems($reportIds))
            ->concat($this->disputeItems($employeeId))
            ->unique('key')
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function approvalItems(User $user): Collection
    {
        $seen = [];

        return $this->approvals->pendingFor($user)
            ->map(fn ($step): ?array => $this->approvalItem($step, $seen))
            ->filter()
            ->values();
    }

    /**
     * @param  array<string, bool>  $seen
     * @return array<string, mixed>|null
     */
    private function approvalItem($step, array &$seen): ?array
    {
        $approval = $step->approval;

        if ($approval === null || isset($seen[$approval->id])) {
            return null;
        }

        $seen[$approval->id] = true;

        return [
            'key' => "approval:{$approval->id}",
            'type' => 'approval',
            'title' => "Decide: {$approval->subject}",
            'subtitle' => $approval->action,
            'priority' => 'high',
            'due_at' => null,
            'meta' => ['approval_id' => $approval->id],
            'source_id' => $approval->id,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function caseTaskItems(User $user, ?int $employeeId, array $reportIds, int $dueWithinDays): Collection
    {
        $cutoff = today()->addDays($dueWithinDays)->toDateString();

        $mine = fn ($task): bool => $employeeId !== null
            && (int) $task->owner_employee_id === (int) $employeeId
            || in_array((int) $task->owner_employee_id, $reportIds, true);

        $hr = fn ($task, string $permission): bool => $task->owner_scope === 'hr'
            && $user->hasPermission($permission);

        $visible = function ($task) use ($mine, $hr): ?string {
            if ($mine($task)) {
                return null;
            }

            if ($task instanceof OnboardingCaseTask && $hr($task, 'hrms.onboarding.manage')) {
                return null;
            }

            if ($task instanceof OffboardingCaseTask && $hr($task, 'hrms.offboarding.manage')) {
                return null;
            }

            return 'hidden';
        };

        $collect = function ($tasks, string $prefix) use ($visible, $cutoff): Collection {
            return $tasks
                ->filter(fn ($task) => $task->status->value === CaseTaskStatus::Pending->value
                    && ($task->due_date === null || $task->due_date->toDateString() <= $cutoff)
                    && $visible($task) === null)
                ->map(fn ($task) => [
                    'key' => "{$prefix}:{$task->id}",
                    'type' => 'case_task',
                    'title' => $task->title,
                    'subtitle' => $task->owner?->displayName(),
                    'priority' => $task->due_date !== null && $task->due_date->isPast() ? 'high' : 'normal',
                    'due_at' => $task->due_date?->toDateString(),
                    'meta' => ['case_task_id' => $task->id, 'case_id' => $task->case_id],
                    'source_id' => $task->id,
                ]);
        };

        $onboarding = OnboardingCaseTask::query()->open()
            ->where(fn ($query) => $query
                ->where('status', CaseTaskStatus::Pending->value)
                ->where(fn ($nested) => $nested
                    ->whereNull('due_date')
                    ->orWhereDate('due_date', '<=', $cutoff)))
            ->with(['owner:id,employee_code,name', 'case:id'])
            ->orderBy('id')
            ->get();

        $offboarding = OffboardingCaseTask::query()->open()
            ->where(fn ($query) => $query
                ->where('status', CaseTaskStatus::Pending->value)
                ->where(fn ($nested) => $nested
                    ->whereNull('due_date')
                    ->orWhereDate('due_date', '<=', $cutoff)))
            ->with(['owner:id,employee_code,name', 'case:id'])
            ->orderBy('id')
            ->get();

        return $collect($onboarding, 'onboarding_task')->concat($collect($offboarding, 'offboarding_task'));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function regularizationItems(?int $employeeId, array $reportIds): Collection
    {
        if ($employeeId === null && $reportIds === []) {
            return collect();
        }

        $ids = array_filter([$employeeId, ...$reportIds]);

        return AttendanceRegularizationRequest::query()->whereIn('employee_id', $ids)
            ->where('status', 'pending')
            ->with('employee:id,employee_code,name')
            ->orderBy('id')
            ->get()
            ->map(fn (AttendanceRegularizationRequest $request): array => [
                'key' => "attendance_reg:{$request->id}",
                'type' => 'regularization',
                'title' => "Correction for {$request->work_date->toDateString()}",
                'subtitle' => $request->employee?->displayName(),
                'priority' => (int) $request->employee_id === (int) $employeeId ? 'normal' : 'high',
                'due_at' => $request->work_date->toDateString(),
                'meta' => ['regularization_id' => $request->id],
                'source_id' => $request->id,
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function documentAskItems(?int $employeeId): Collection
    {
        if ($employeeId === null) {
            return collect();
        }

        return DocumentRequest::query()->where('employee_id', $employeeId)
            ->outstanding()
            ->with('type:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn (DocumentRequest $ask): array => [
                'key' => "document_request:{$ask->id}",
                'type' => 'document_request',
                'title' => "File: {$ask->title}",
                'subtitle' => $ask->type?->name,
                'priority' => 'normal',
                'due_at' => $ask->due_date?->toDateString(),
                'meta' => ['document_request_id' => $ask->id],
                'source_id' => $ask->id,
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function assetItems(?int $employeeId): Collection
    {
        if ($employeeId === null) {
            return collect();
        }

        return AssetAssignment::query()->where('employee_id', $employeeId)
            ->where('status', 'active')
            ->with('asset:id,asset_code,name')
            ->orderBy('id')
            ->get()
            ->map(fn (AssetAssignment $assignment): array => [
                'key' => "asset_assignment:{$assignment->id}",
                'type' => 'asset',
                'title' => $assignment->acknowledged_at === null
                    ? "Acknowledge {$assignment->asset?->asset_code}"
                    : "Return {$assignment->asset?->asset_code}",
                'subtitle' => $assignment->asset?->name,
                'priority' => $assignment->acknowledged_at === null ? 'high' : 'normal',
                'due_at' => null,
                'meta' => ['asset_assignment_id' => $assignment->id, 'asset_id' => $assignment->asset_id],
                'source_id' => $assignment->id,
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function expiringDocumentItems(array $reportIds): Collection
    {
        $items = collect();

        foreach (Employee::query()->whereIn('id', $reportIds)->get(['id', 'employee_code', 'name']) as $report) {
            foreach ($this->documents->expireSoon($report, 30) as $document) {
                $items->push([
                    'key' => "document_expiring:{$document->id}",
                    'type' => 'document_expiring',
                    'title' => "{$report->displayName()}’s {$document->title} expires",
                    'subtitle' => $document->expires_at?->toDateString(),
                    'priority' => $document->expires_at !== null && $document->expires_at->lessThanOrEqualTo(today()->addDays(7)) ? 'high' : 'normal',
                    'due_at' => $document->expires_at?->toDateString(),
                    'meta' => ['document_id' => $document->id, 'employee_id' => $report->id],
                    'source_id' => $document->id,
                ]);
            }
        }

        return $items;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function disputeItems(?int $employeeId): Collection
    {
        if ($employeeId === null) {
            return collect();
        }

        return Payslip::query()->where('employee_id', $employeeId)
            ->where('status', 'disputed')
            ->with('run:id,period_year,period_month')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Payslip $payslip): array => [
                'key' => "payslip:{$payslip->id}",
                'type' => 'payslip_dispute',
                'title' => 'Payslip dispute open',
                'subtitle' => $payslip->run === null ? null : "{$payslip->run->period_year}-{$payslip->run->period_month}",
                'priority' => 'high',
                'due_at' => null,
                'meta' => ['payslip_id' => $payslip->id, 'payroll_run_id' => $payslip->payroll_run_id],
                'source_id' => $payslip->id,
            ]);
    }
}
