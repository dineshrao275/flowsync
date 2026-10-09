<?php

namespace App\Services\Notifications\Families;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Performance\PerformanceCycle;
use App\Models\Hrms\Performance\ReviewSummary;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\BaseNotificationFamily;

/**
 * The `hrms.performance` notification family (moved verbatim out of NotificationService, P1.6).
 */
class PerformanceNotifications extends BaseNotificationFamily
{
    public function family(): string
    {
        return 'hrms.performance';
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

        $recipients = $this->usersById($userIds);
        foreach ($userIds as $userId) {
            $recipient = $recipients->get((int) $userId);

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
     * Tell every participant their cycle is live: sheets validated, rooms
     * opening. The creation told HR; this tells the people with goals.
     *
     * @return list<UserNotification>
     */
    public function performanceCycleOpened(PerformanceCycle $cycle, ?User $actor = null): array
    {
        $userIds = Employee::query()->whereIn(
            'id',
            $cycle->goals()->distinct()->pluck('employee_id'),
        )->whereNotNull('user_id')->pluck('user_id')->unique();

        $sent = [];

        $recipients = $this->usersById($userIds);
        foreach ($userIds as $userId) {
            $recipient = $recipients->get((int) $userId);

            if ($recipient === null || ($actor !== null && (int) $recipient->id === (int) $actor->id)) {
                continue;
            }

            $sent[] = $this->notify($recipient, 'hrms.performance.cycle_opened', [
                'performance_cycle_id' => $cycle->id,
                'cycle_name' => $cycle->name,
            ], $actor);
        }

        return $sent;
    }

    /**
     * Tell the reviewee their write-up was shared: the manager's half is
     * readable now, which is the event — not the filing, which they could
     * not see.
     */
    public function performanceReviewShared(ReviewSummary $review, ?User $actor = null): ?UserNotification
    {
        $userId = $review->employee?->user_id;

        if ($userId === null) {
            return null;
        }

        $recipient = User::find((int) $userId);

        if ($recipient === null || ($actor !== null && (int) $recipient->id === (int) $actor->id)) {
            return null;
        }

        return $this->notify($recipient, 'hrms.performance.review_shared', [
            'review_summary_id' => $review->id,
            'performance_cycle_id' => $review->cycle_id,
            'cycle_name' => $review->cycle?->name,
        ], $actor);
    }
}
