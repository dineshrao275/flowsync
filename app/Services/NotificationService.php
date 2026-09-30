<?php

namespace App\Services;

use App\Events\NotificationSent;
use App\Models\Comment;
use App\Models\Hrms\CompOff\CompOffRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Expense\ExpenseClaim;
use App\Models\Hrms\Leave\LeaveRequest;
use App\Models\Hrms\Lifecycle\OnboardingCaseTask;
use App\Models\Hrms\Payroll\PayrollRun;
use App\Models\Hrms\Performance\PerformanceCycle;
use App\Models\Hrms\Performance\ReviewSummary;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WorkLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class NotificationService
{
    public function notify(
        User $recipient,
        string $type,
        array $data = [],
        ?User $actor = null,
    ): UserNotification {
        $notification = new UserNotification([
            'user_id' => $recipient->id,
            'actor_id' => $actor?->id,
            'type' => $type,
            'data' => $data,
        ]);
        $notification->save();

        broadcast(new NotificationSent($notification));

        return $notification;
    }

    public function forUser(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return UserNotification::query()
            ->with('actor:id,name')
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function unreadCount(User $user): int
    {
        return UserNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();
    }

    public function markAllRead(User $user): int
    {
        return UserNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function taskAssigned(User $actor, Task $task, ?User $assignee = null): ?UserNotification
    {
        $assignee ??= $task->assignee;

        if ($assignee === null || $assignee->id === $actor->id) {
            return null;
        }

        return $this->notify($assignee, 'task.assigned', $this->taskPayload($task), $actor);
    }

    public function taskStatusChanged(User $actor, Task $task, string $fromStatus, string $toStatus): ?UserNotification
    {
        $assignee = $task->assignee;

        if ($assignee === null || $assignee->id === $actor->id) {
            return null;
        }

        return $this->notify($assignee, 'task.status_changed', array_merge($this->taskPayload($task), [
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
        ]), $actor);
    }

    /**
     * Notifies the task assignee/reporter plus any @mentioned users
     * (excluding the acting user). Returns the notifications created.
     *
     * @return list<UserNotification>
     */
    public function taskCommented(User $actor, Task $task, Comment $comment): array
    {
        $recipientIds = collect([$task->assignee_id, $task->reporter_id])
            ->filter()
            ->concat($this->mentionUsers($comment->comment)->pluck('id'))
            ->unique()
            ->reject(fn ($id) => (int) $id === $actor->id)
            ->values();

        $sent = [];
        foreach ($recipientIds as $recipientId) {
            $recipient = User::find((int) $recipientId);

            if ($recipient === null) {
                continue;
            }

            $sent[] = $this->notify($recipient, 'task.commented', array_merge($this->taskPayload($task), [
                'comment_id' => $comment->id,
                'snippet' => mb_strimwidth($comment->comment, 0, 120, '…'),
            ]), $actor);
        }

        return $sent;
    }

    public function taskUnblocked(User $actor, Task $task, ?Task $blocker = null): ?UserNotification
    {
        $assignee = $task->assignee;

        if ($assignee === null || $assignee->id === $actor->id) {
            return null;
        }

        $data = $this->taskPayload($task);

        if ($blocker !== null) {
            $data['blocked_by'] = [
                'id' => $blocker->id,
                'key' => $blocker->key,
                'title' => $blocker->title,
            ];
        }

        return $this->notify($assignee, 'task.unblocked', $data, $actor);
    }

    public function workLogAdded(User $actor, Task $task, WorkLog $log): ?UserNotification
    {
        $assignee = $task->assignee;

        if ($assignee === null || $assignee->id === $actor->id) {
            return null;
        }

        return $this->notify($assignee, 'task.work_logged', array_merge($this->taskPayload($task), [
            'work_log_id' => $log->id,
            'duration_minutes' => $log->duration_minutes,
        ]), $actor);
    }

    /**
     * Nudge whoever must act on a leave ask next: the current step's named
     * approver, or every holder of its role step. Skips the actor, mirroring
     * taskCommented — a manager filing for a report does not need a toast
     * about their own filing.
     *
     * @return list<UserNotification>
     */
    public function leaveRequested(LeaveRequest $request, ?User $actor = null): array
    {
        $step = $request->approval?->currentStepRecord();

        if ($step === null) {
            return [];
        }

        $recipientIds = $step->approver_user_id !== null
            ? [(int) $step->approver_user_id]
            : $this->usersWithRole((int) $step->approver_role_id);

        $sent = [];

        foreach ($recipientIds as $recipientId) {
            $recipient = User::find($recipientId);

            if ($recipient === null || ($actor !== null && (int) $recipient->id === (int) $actor->id)) {
                continue;
            }

            $sent[] = $this->notify($recipient, 'hrms.leave.requested', $this->leavePayload($request), $actor);
        }

        return $sent;
    }

    /**
     * Tell the requester their ask was decided, with the transition named.
     * Skips the actor: a manager approving from the queue already watched
     * it happen, but the requester never does.
     */
    public function leaveDecided(LeaveRequest $request, string $fromStatus, ?User $actor = null): ?UserNotification
    {
        $userId = $request->employee?->user_id;

        if ($userId === null) {
            return null;
        }

        $recipient = User::find((int) $userId);

        if ($recipient === null || ($actor !== null && (int) $recipient->id === (int) $actor->id)) {
            return null;
        }

        $type = $request->status->value === 'approved' ? 'hrms.leave.approved' : 'hrms.leave.rejected';

        return $this->notify($recipient, $type, array_merge($this->leavePayload($request), [
            'from_status' => $fromStatus,
            'to_status' => $request->status->value,
        ]), $actor);
    }

    /**
     * Nudge whoever must act on a comp-off ask next: the current step's
     * named approver, or every holder of its role step. Skips the actor,
     * mirroring the leave nudge — a manager filing for a report does not
     * need a toast about their own filing.
     *
     * @return list<UserNotification>
     */
    public function compOffRequested(CompOffRequest $request, ?User $actor = null): array
    {
        $step = $request->approval?->currentStepRecord();

        if ($step === null) {
            return [];
        }

        $recipientIds = $step->approver_user_id !== null
            ? [(int) $step->approver_user_id]
            : $this->usersWithRole((int) $step->approver_role_id);

        $sent = [];

        foreach ($recipientIds as $recipientId) {
            $recipient = User::find($recipientId);

            if ($recipient === null || ($actor !== null && (int) $recipient->id === (int) $actor->id)) {
                continue;
            }

            $sent[] = $this->notify($recipient, 'hrms.comp_off.requested', $this->compOffPayload($request), $actor);
        }

        return $sent;
    }

    /**
     * Tell the requester their comp-off ask was decided, with the
     * transition named. Skips the actor like the leave twin.
     */
    public function compOffDecided(CompOffRequest $request, string $fromStatus, ?User $actor = null): ?UserNotification
    {
        $userId = $request->employee?->user_id;

        if ($userId === null) {
            return null;
        }

        $recipient = User::find((int) $userId);

        if ($recipient === null || ($actor !== null && (int) $recipient->id === (int) $actor->id)) {
            return null;
        }

        $type = $request->status->value === 'approved' ? 'hrms.comp_off.approved' : 'hrms.comp_off.rejected';

        return $this->notify($recipient, $type, array_merge($this->compOffPayload($request), [
            'from_status' => $fromStatus,
            'to_status' => $request->status->value,
        ]), $actor);
    }

    /**
     * Nudge whoever owns an onboarding checklist item that is coming due.
     *
     * Two legs, deliberately asymmetric: the owner always hears about their
     * own item, and the HR managers additionally hear about hr-scoped items —
     * because an hr item with no named owner belongs to the pool, and a pool
     * nobody nudges is a pile nobody works. Anyone else’s item is none of
     * their business, which is why manager- and employee-scoped items notify
     * only the owner.
     *
     * @return list<UserNotification>
     */
    public function onboardingTaskDue(Employee $employee, OnboardingCaseTask $task, ?User $actor = null): array
    {
        $recipientIds = collect();

        $owner = $task->owner_employee_id !== null ? Employee::find($task->owner_employee_id) : null;

        if ($owner?->user_id !== null) {
            $recipientIds->push((int) $owner->user_id);
        }

        if ($task->owner_scope === 'hr') {
            $recipientIds = $recipientIds->concat($this->usersWith('hrms.onboarding.manage'));
        }

        $sent = [];
        foreach ($recipientIds->unique()->reject(fn ($id) => $actor !== null && (int) $id === $actor->id)->values() as $recipientId) {
            $recipient = User::find((int) $recipientId);

            if ($recipient === null) {
                continue;
            }

            $sent[] = $this->notify($recipient, 'hrms.onboarding.task_due', [
                'onboarding_case_id' => $task->case_id,
                'case_task_id' => $task->id,
                'title' => $task->title,
                'employee_name' => $employee->displayName(),
                'due_date' => $task->due_date?->toDateString(),
            ], $actor);
        }

        return $sent;
    }

    /**
     * Tell everyone on a published run their payslip is ready. Amounts
     * never travel in notification data (D2.17.8 / R2) — the payslip
     * itself carries the numbers behind its own access log.
     *
     * @return list<UserNotification>
     */
    public function payrollPublished(PayrollRun $run, ?User $actor = null): array
    {
        $sent = [];

        foreach ($run->payslips()->with('employee:id,user_id')->get() as $payslip) {
            $userId = $payslip->employee?->user_id;

            if ($userId === null) {
                continue;
            }

            $recipient = User::find((int) $userId);

            if ($recipient === null || ($actor !== null && (int) $recipient->id === (int) $actor->id)) {
                continue;
            }

            $sent[] = $this->notify($recipient, 'hrms.payroll.published', [
                'payroll_run_id' => $run->id,
                'period_year' => $run->period_year,
                'period_month' => $run->period_month,
                'payslip_id' => $payslip->id,
            ], $actor);
        }

        return $sent;
    }

    /**
     * Nudge whoever must act on an expense claim next: the current step's
     * named approver, or every holder of its role step. Skips the actor,
     * mirroring the leave nudge — filing never toasts the filer.
     *
     * @return list<UserNotification>
     */
    public function expenseSubmitted(ExpenseClaim $claim, ?User $actor = null): array
    {
        $step = $claim->approval?->currentStepRecord();

        if ($step === null) {
            return [];
        }

        $recipientIds = $step->approver_user_id !== null
            ? [(int) $step->approver_user_id]
            : $this->usersWithRole((int) $step->approver_role_id);

        $sent = [];

        foreach ($recipientIds as $recipientId) {
            $recipient = User::find($recipientId);

            if ($recipient === null || ($actor !== null && (int) $recipient->id === (int) $actor->id)) {
                continue;
            }

            $sent[] = $this->notify($recipient, 'hrms.expense.submitted', $this->expensePayload($claim), $actor);
        }

        return $sent;
    }

    /**
     * Tell the claimant their money was decided, with the transition named.
     * No figures travel — amounts never ride notifications, and the totals
     * wait in the app behind its own access rules.
     */
    public function expenseDecided(ExpenseClaim $claim, string $fromStatus, ?User $actor = null): ?UserNotification
    {
        $userId = $claim->employee?->user_id;

        if ($userId === null) {
            return null;
        }

        $recipient = User::find((int) $userId);

        if ($recipient === null || ($actor !== null && (int) $recipient->id === (int) $actor->id)) {
            return null;
        }

        $type = $claim->status->value === 'approved' ? 'hrms.expense.approved' : 'hrms.expense.rejected';

        return $this->notify($recipient, $type, array_merge($this->expensePayload($claim), [
            'from_status' => $fromStatus,
            'to_status' => $claim->status->value,
        ]), $actor);
    }

    /**
     * Tell the claimant their money is on its way through payroll.
     */
    public function expensePaid(ExpenseClaim $claim, ?User $actor = null): ?UserNotification
    {
        $userId = $claim->employee?->user_id;

        if ($userId === null) {
            return null;
        }

        $recipient = User::find((int) $userId);

        if ($recipient === null || ($actor !== null && (int) $recipient->id === (int) $actor->id)) {
            return null;
        }

        return $this->notify($recipient, 'hrms.expense.paid', $this->expensePayload($claim), $actor);
    }

    /**
     * Tell every participant their cycle sealed: goal owners with a login,
     * skipping the actor like every other nudge. The receipt, not the
     * rating — completed locks the rooms, and this is how the people
     * inside hear it.
     *
     * @return list<UserNotification>
     */
    public function performanceCycleCompleted(PerformanceCycle $cycle, ?User $actor = null): array
    {
        $userIds = Employee::query()->whereIn(
            'id',
            $cycle->goals()->distinct()->pluck('employee_id'),
        )->whereNotNull('user_id')->pluck('user_id')->unique();

        $sent = [];

        foreach ($userIds as $userId) {
            $recipient = User::find((int) $userId);

            if ($recipient === null || ($actor !== null && (int) $recipient->id === (int) $actor->id)) {
                continue;
            }

            $sent[] = $this->notify($recipient, 'hrms.performance.cycle_completed', [
                'performance_cycle_id' => $cycle->id,
                'cycle_name' => $cycle->name,
            ], $actor);
        }

        return $sent;
    }

    /**
     * Tell the reviewee their write-up was acknowledged: sealed and shared,
     * with the receipt landing where the person it is about can see it.
     */
    public function performanceReviewAcknowledged(ReviewSummary $review, ?User $actor = null): ?UserNotification
    {
        $userId = $review->employee?->user_id;

        if ($userId === null) {
            return null;
        }

        $recipient = User::find((int) $userId);

        if ($recipient === null || ($actor !== null && (int) $recipient->id === (int) $actor->id)) {
            return null;
        }

        return $this->notify($recipient, 'hrms.performance.review_acknowledged', [
            'review_summary_id' => $review->id,
            'performance_cycle_id' => $review->cycle_id,
            'cycle_name' => $review->cycle?->name,
        ], $actor);
    }

    /**
     * Every login holding a permission, for pool-owned notifications (an hr
     * item belongs to whoever holds manage, not to a named person).
     *
     * @return list<int>
     */
    private function usersWith(string $permission): array
    {
        return User::whereHas('roles.permissions', fn ($query) => $query->where('slug', $permission))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Resolves @mention tokens in text to users in the current tenant database.
     * Tokens match a user's full email, email local part (e.g. @viewer matches
     * viewer@flowsync.test), or name, case-insensitively.
     *
     * @return Collection<int, User>
     */
    public function mentionUsers(string $text): Collection
    {
        preg_match_all('/@([A-Za-z0-9._-]+)/', $text, $matches);

        $users = collect();
        foreach ($matches[1] as $token) {
            $token = mb_strtolower($token);

            $query = User::query()->where(function ($query) use ($token) {
                if (str_contains($token, '@')) {
                    $query->whereRaw('LOWER(email) = ?', [$token]);
                } else {
                    $query->whereRaw('LOWER(email) LIKE ?', [$token.'@%'])
                        ->orWhereRaw('LOWER(name) = ?', [$token]);
                }
            });

            $users = $users->concat($query->get());
        }

        return $users->unique('id')->values();
    }

    private function taskPayload(Task $task): array
    {
        return [
            'task_id' => $task->id,
            'key' => $task->key,
            'title' => $task->title,
            'project_id' => $task->project_id,
            'project_name' => $task->project?->name,
            'workspace_id' => $task->workspace_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function leavePayload(LeaveRequest $request): array
    {
        return [
            'leave_request_id' => $request->id,
            'employee_id' => $request->employee_id,
            'employee_name' => $request->employee?->name,
            'leave_type_id' => $request->leave_type_id,
            'leave_type_name' => $request->type?->name,
            'from_date' => $request->from_date->toDateString(),
            'to_date' => $request->to_date->toDateString(),
            'total_days' => (float) $request->total_days,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function compOffPayload(CompOffRequest $request): array
    {
        return [
            'comp_off_request_id' => $request->id,
            'employee_id' => $request->employee_id,
            'employee_name' => $request->employee?->name,
            'from_date' => $request->from_date->toDateString(),
            'to_date' => $request->to_date->toDateString(),
            'total_minutes' => $request->total_minutes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function expensePayload(ExpenseClaim $claim): array
    {
        return [
            'expense_claim_id' => $claim->id,
            'claim_number' => $claim->claim_number,
            'employee_id' => $claim->employee_id,
            'employee_name' => $claim->employee?->name,
        ];
    }

    /**
     * Every login holding a role id, for role-step approvals (an HR step
     * belongs to whoever holds the role, not to a named person).
     *
     * @return list<int>
     */
    private function usersWithRole(int $roleId): array
    {
        return User::whereHas('roles', fn ($query) => $query->where('roles.id', $roleId))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
