<?php

namespace App\Services\Notifications\Families;

use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Lifecycle\OffboardingCase;
use App\Models\Hrms\Lifecycle\OnboardingCaseTask;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\BaseNotificationFamily;

/**
 * The `hrms.lifecycle` notification family (moved verbatim out of NotificationService, P1.6).
 */
class LifecycleNotifications extends BaseNotificationFamily
{
    public function family(): string
    {
        return 'hrms.lifecycle';
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
        $loopIds = $recipientIds->unique()->reject(fn ($id) => $actor !== null && (int) $id === $actor->id)->values();
        $recipients = $this->usersById($loopIds);
        foreach ($loopIds as $recipientId) {
            $recipient = $recipients->get((int) $recipientId);

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
     * Tell the document's owner their file was accepted — or refused, with
     * HR's reason attached so the rejection is actionable, not just bad
     * news. The owner hears, never the filer: HR filing on someone's
     * behalf must not toast HR about their own filing.
     */
    public function documentDecided(EmployeeDocument $document, string $fromStatus, ?User $actor = null): ?UserNotification
    {
        $userId = $document->employee?->user_id;

        if ($userId === null) {
            return null;
        }

        $recipient = User::find((int) $userId);

        if ($recipient === null || ($actor !== null && (int) $recipient->id === (int) $actor->id)) {
            return null;
        }

        $type = $document->status->value === 'verified' ? 'hrms.document.verified' : 'hrms.document.rejected';

        return $this->notify($recipient, $type, array_merge($this->documentPayload($document), [
            'from_status' => $fromStatus,
            'to_status' => $document->status->value,
        ]), $actor);
    }

    /**
     * Tell HR managers an exit opened already blocked: hardware out, file
     * asks pending — the clearance names them, and this is the nudge that
     * says so on day one instead of at the refused sign-off.
     *
     * @return list<UserNotification>
     */
    public function offboardingClearancePending(OffboardingCase $case, ?User $actor = null): array
    {
        $sent = [];

        $loopIds = $this->usersWith('hrms.offboarding.manage');
        $recipients = $this->usersById($loopIds);
        foreach ($loopIds as $recipientId) {
            $recipient = $recipients->get((int) $recipientId);

            if ($recipient === null || ($actor !== null && (int) $recipient->id === (int) $actor->id)) {
                continue;
            }

            $sent[] = $this->notify($recipient, 'hrms.offboarding.clearance_pending', [
                'offboarding_case_id' => $case->id,
                'employee_id' => $case->employee_id,
                'employee_name' => $case->employee?->name,
            ], $actor);
        }

        return $sent;
    }

    /**
     * @return array<string, mixed>
     */
    private function documentPayload(EmployeeDocument $document): array
    {
        return [
            'document_id' => $document->id,
            'employee_id' => $document->employee_id,
            'employee_name' => $document->employee?->displayName(),
            'title' => $document->title,
        ];
    }
}
