<?php

namespace App\Services\Notifications\Families;

use App\Models\Hrms\Asset\AssetAssignment;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\BaseNotificationFamily;

/**
 * The `hrms.asset` notification family (moved verbatim out of NotificationService, P1.6).
 */
class AssetNotifications extends BaseNotificationFamily
{
    public function family(): string
    {
        return 'hrms.asset';
    }

    /**
     * Tell the holder their hardware is waiting on their signature. The
     * assignee always hears; manage-holders hear only about the overdue
     * ones (a pool nobody nudges is a pile nobody works — the onboarding
     * asymmetry, one context over).
     *
     * @return list<UserNotification>
     */
    public function assetAssigned(AssetAssignment $assignment, ?User $actor = null): array
    {
        $userId = $assignment->employee?->user_id;

        if ($userId === null) {
            return [];
        }

        $recipient = User::find((int) $userId);

        if ($recipient === null || ($actor !== null && (int) $recipient->id === (int) $actor->id)) {
            return [];
        }

        return [$this->notify($recipient, 'hrms.asset.assigned', $this->assetPayload($assignment), $actor)];
    }

    /**
     * Tell whoever handed it over that the receipt came back — the
     * assigner, or the manage pool when nobody assigner-shaped exists.
     */
    public function assetAcknowledged(AssetAssignment $assignment, ?User $actor = null): ?UserNotification
    {
        return $this->notifyAssetPool($assignment, 'hrms.asset.acknowledged', $actor);
    }

    /**
     * Tell whoever handed it over that it came back, with its condition.
     */
    public function assetReturned(AssetAssignment $assignment, ?User $actor = null): ?UserNotification
    {
        return $this->notifyAssetPool($assignment, 'hrms.asset.returned', $actor);
    }

    /**
     * Nudge an overdue signature: the holder always, the manage pool too.
     *
     * @return list<UserNotification>
     */
    public function assetReturnOverdue(AssetAssignment $assignment, ?User $actor = null): array
    {
        $sent = [];
        $recipientIds = collect();

        if ($assignment->employee?->user_id !== null) {
            $recipientIds->push((int) $assignment->employee->user_id);
        }

        $recipientIds = $recipientIds->concat($this->usersWith('hrms.assets.manage'));

        $loopIds = $recipientIds->unique()->reject(fn ($id) => $actor !== null && (int) $id === $actor->id)->values();
        $recipients = $this->usersById($loopIds);
        foreach ($loopIds as $recipientId) {
            $recipient = $recipients->get((int) $recipientId);

            if ($recipient === null) {
                continue;
            }

            $sent[] = $this->notify($recipient, 'hrms.asset.return_overdue', $this->assetPayload($assignment), $actor);
        }

        return $sent;
    }

    /**
     * The assigner hears about their own handover; without one, the manage
     * pool does. Skips the actor either way — handing over never toasts
     * the hand.
     */
    private function notifyAssetPool(AssetAssignment $assignment, string $type, ?User $actor = null): ?UserNotification
    {
        $recipientIds = $assignment->assigned_by_user_id !== null
            ? [(int) $assignment->assigned_by_user_id]
            : $this->usersWith('hrms.assets.manage');

        $recipients = $this->usersById($recipientIds);
        foreach ($recipientIds as $recipientId) {
            $recipient = $recipients->get((int) $recipientId);

            if ($recipient === null || ($actor !== null && (int) $recipient->id === (int) $actor->id)) {
                continue;
            }

            return $this->notify($recipient, $type, $this->assetPayload($assignment), $actor);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function assetPayload(AssetAssignment $assignment): array
    {
        return [
            'asset_assignment_id' => $assignment->id,
            'asset_id' => $assignment->asset_id,
            'asset_code' => $assignment->asset?->asset_code,
            'employee_id' => $assignment->employee_id,
            'employee_name' => $assignment->employee?->name,
        ];
    }
}
