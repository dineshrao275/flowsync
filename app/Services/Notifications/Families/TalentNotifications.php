<?php

declare(strict_types=1);

namespace App\Services\Notifications\Families;

use App\Models\Hrms\Performance\ReviewSummary;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\BaseNotificationFamily;

/**
 * The `hrms.talent` notification family (Phase 24).
 *
 * One event: a sealed review. The receipt lands where the person it is
 * about can see it (their performance home), carrying identifiers only —
 * ratings never travel in notification data.
 */
class TalentNotifications extends BaseNotificationFamily
{
    public function family(): string
    {
        return 'hrms.talent';
    }

    /**
     * Tell the reviewee their write-up was finalized: sealed and counted,
     * with the receipt landing where the person it is about can see it.
     * Skips the actor and the login-less like every other nudge.
     */
    public function talentReviewFinalized(ReviewSummary $review, ?User $actor = null): ?UserNotification
    {
        $userId = $review->employee?->user_id;

        if ($userId === null) {
            return null;
        }

        $recipient = User::find((int) $userId);

        if ($recipient === null || ($actor !== null && (int) $recipient->id === (int) $actor->id)) {
            return null;
        }

        return $this->notify($recipient, 'talent.review_finalized', [
            'review_summary_id' => $review->id,
            'performance_cycle_id' => $review->cycle_id,
            'cycle_name' => $review->cycle?->name,
        ], $actor);
    }
}
