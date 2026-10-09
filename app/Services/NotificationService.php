<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\Hrms\Asset\AssetAssignment;
use App\Models\Hrms\CompOff\CompOffRequest;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Expense\ExpenseClaim;
use App\Models\Hrms\Leave\LeaveRequest;
use App\Models\Hrms\Lifecycle\OffboardingCase;
use App\Models\Hrms\Lifecycle\OnboardingCaseTask;
use App\Models\Hrms\Payroll\PayrollRun;
use App\Models\Hrms\Performance\PerformanceCycle;
use App\Models\Hrms\Performance\ReviewSummary;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WorkLog;
use App\Services\Notifications\Families\AssetNotifications;
use App\Services\Notifications\Families\ExpenseNotifications;
use App\Services\Notifications\Families\LifecycleNotifications;
use App\Services\Notifications\Families\PerformanceNotifications;
use App\Services\Notifications\Families\TaskNotifications;
use App\Services\Notifications\Families\TimeOffNotifications;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\RecipientResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Facade over the per-family notification classes in App\Services\Notifications
 * (P1.6). Every caller keeps using these methods; the rules live in the
 * families, and delivery lives in NotificationDispatcher.
 */
class NotificationService
{
    public function __construct(
        private NotificationDispatcher $dispatcher,
        private RecipientResolver $resolver,
        private TaskNotifications $task,
        private TimeOffNotifications $timeOff,
        private LifecycleNotifications $lifecycle,
        private ExpenseNotifications $expense,
        private PerformanceNotifications $performance,
        private AssetNotifications $asset,
    ) {}

    public function notify(
        User $recipient,
        string $type,
        array $data = [],
        ?User $actor = null,
    ): UserNotification {
        return $this->dispatcher->notify($recipient, $type, $data, $actor);
    }

    public function forUser(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return $this->dispatcher->forUser($user, $perPage);
    }

    public function unreadCount(User $user): int
    {
        return $this->dispatcher->unreadCount($user);
    }

    public function markAllRead(User $user): int
    {
        return $this->dispatcher->markAllRead($user);
    }

    /**
     * @return Collection<int, User>
     */
    public function mentionUsers(string $text): Collection
    {
        return $this->resolver->mentionUsers($text);
    }

    public function taskAssigned(User $actor, Task $task, ?User $assignee = null): ?UserNotification
    {
        return $this->task->taskAssigned($actor, $task, $assignee);
    }

    public function taskStatusChanged(User $actor, Task $task, string $fromStatus, string $toStatus): ?UserNotification
    {
        return $this->task->taskStatusChanged($actor, $task, $fromStatus, $toStatus);
    }

    public function taskCommented(User $actor, Task $task, Comment $comment): array
    {
        return $this->task->taskCommented($actor, $task, $comment);
    }

    public function taskUnblocked(User $actor, Task $task, ?Task $blocker = null): ?UserNotification
    {
        return $this->task->taskUnblocked($actor, $task, $blocker);
    }

    public function workLogAdded(User $actor, Task $task, WorkLog $log): ?UserNotification
    {
        return $this->task->workLogAdded($actor, $task, $log);
    }

    public function leaveRequested(LeaveRequest $request, ?User $actor = null): array
    {
        return $this->timeOff->leaveRequested($request, $actor);
    }

    public function leaveDecided(LeaveRequest $request, string $fromStatus, ?User $actor = null): ?UserNotification
    {
        return $this->timeOff->leaveDecided($request, $fromStatus, $actor);
    }

    public function compOffRequested(CompOffRequest $request, ?User $actor = null): array
    {
        return $this->timeOff->compOffRequested($request, $actor);
    }

    public function compOffDecided(CompOffRequest $request, string $fromStatus, ?User $actor = null): ?UserNotification
    {
        return $this->timeOff->compOffDecided($request, $fromStatus, $actor);
    }

    public function onboardingTaskDue(Employee $employee, OnboardingCaseTask $task, ?User $actor = null): array
    {
        return $this->lifecycle->onboardingTaskDue($employee, $task, $actor);
    }

    public function documentDecided(EmployeeDocument $document, string $fromStatus, ?User $actor = null): ?UserNotification
    {
        return $this->lifecycle->documentDecided($document, $fromStatus, $actor);
    }

    public function offboardingClearancePending(OffboardingCase $case, ?User $actor = null): array
    {
        return $this->lifecycle->offboardingClearancePending($case, $actor);
    }

    public function payrollPublished(PayrollRun $run, ?User $actor = null): array
    {
        return $this->expense->payrollPublished($run, $actor);
    }

    public function expenseSubmitted(ExpenseClaim $claim, ?User $actor = null): array
    {
        return $this->expense->expenseSubmitted($claim, $actor);
    }

    public function expenseDecided(ExpenseClaim $claim, string $fromStatus, ?User $actor = null): ?UserNotification
    {
        return $this->expense->expenseDecided($claim, $fromStatus, $actor);
    }

    public function expensePaid(ExpenseClaim $claim, ?User $actor = null): ?UserNotification
    {
        return $this->expense->expensePaid($claim, $actor);
    }

    public function performanceCycleCompleted(PerformanceCycle $cycle, ?User $actor = null): array
    {
        return $this->performance->performanceCycleCompleted($cycle, $actor);
    }

    public function performanceReviewAcknowledged(ReviewSummary $review, ?User $actor = null): ?UserNotification
    {
        return $this->performance->performanceReviewAcknowledged($review, $actor);
    }

    public function performanceCycleOpened(PerformanceCycle $cycle, ?User $actor = null): array
    {
        return $this->performance->performanceCycleOpened($cycle, $actor);
    }

    public function performanceReviewShared(ReviewSummary $review, ?User $actor = null): ?UserNotification
    {
        return $this->performance->performanceReviewShared($review, $actor);
    }

    public function assetAssigned(AssetAssignment $assignment, ?User $actor = null): array
    {
        return $this->asset->assetAssigned($assignment, $actor);
    }

    public function assetAcknowledged(AssetAssignment $assignment, ?User $actor = null): ?UserNotification
    {
        return $this->asset->assetAcknowledged($assignment, $actor);
    }

    public function assetReturned(AssetAssignment $assignment, ?User $actor = null): ?UserNotification
    {
        return $this->asset->assetReturned($assignment, $actor);
    }

    public function assetReturnOverdue(AssetAssignment $assignment, ?User $actor = null): array
    {
        return $this->asset->assetReturnOverdue($assignment, $actor);
    }
}
