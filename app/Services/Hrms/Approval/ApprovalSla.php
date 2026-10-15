<?php

namespace App\Services\Hrms\Approval;

use App\Enums\Hrms\ApprovalStatus;
use App\Models\Hrms\Shared\Approval;
use App\Models\Hrms\Shared\ApprovalStep;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use App\Services\Notifications\NotificationDispatcher;

/**
 * Approval/HRMS — the SLA clock of an open approval (P2.4).
 *
 * `approvals.due_at` is set when a stage becomes current (see ApprovalStages).
 * This sweep, run hourly per tenant, does two things, each AT MOST ONCE per
 * stage (the `reminded_at` / `escalated_at` stamps are cleared when the
 * cursor moves):
 *
 *  - **reminder** — from `due_at - reminder_before_hours` (default 4h) nudge
 *    everyone who can act on the current stage;
 *  - **escalation** — once `due_at` has passed, tell the holders of the
 *    escalation role (template's, else `approvals.default_escalation_role`)
 *    and the audit trail. Escalation notifies; it never approves on anyone's
 *    behalf — acting stays with the R11 override.
 */
class ApprovalSla
{
    public const DEFAULT_REMINDER_HOURS = 4;

    public function __construct(
        private readonly ChainTemplates $templates,
        private readonly NotificationDispatcher $notifier,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /**
     * @return array{reminded: int, escalated: int}
     */
    public function sweep(bool $dryRun = false): array
    {
        $reminded = 0;
        $escalated = 0;

        $open = Approval::query()
            ->where('status', ApprovalStatus::Pending)
            ->whereNotNull('due_at')
            ->get();

        foreach ($open as $approval) {
            $template = $approval->domain !== null && $this->templates->knows($approval->domain)
                ? $this->templates->for($approval->domain)
                : ['reminder_before_hours' => null, 'escalation_role_slug' => null];

            $before = $template['reminder_before_hours'] ?? self::DEFAULT_REMINDER_HOURS;

            if ($approval->reminded_at === null && now()->gte($approval->due_at->copy()->subHours($before))) {
                $reminded++;
                $dryRun || $this->remind($approval);
            }

            if ($approval->escalated_at === null && now()->gte($approval->due_at)) {
                $escalated++;
                $dryRun || $this->escalate($approval, $template['escalation_role_slug'] ?? null);
            }
        }

        return ['reminded' => $reminded, 'escalated' => $escalated];
    }

    private function remind(Approval $approval): void
    {
        foreach ($this->currentApprovers($approval) as $user) {
            $this->notifier->notify($user, 'hrms.approval.reminder', $this->payload($approval));
        }

        $approval->update(['reminded_at' => now()]);
    }

    private function escalate(Approval $approval, ?string $roleSlug): void
    {
        $slug = $roleSlug ?? config('approvals.default_escalation_role');
        $requesterId = $approval->requested_by_user_id;

        $recipients = User::query()
            ->whereHas('roles', fn ($q) => $q->where('roles.slug', $slug))
            ->when($requesterId !== null, fn ($q) => $q->where('users.id', '!=', $requesterId))
            ->get();

        foreach ($recipients as $user) {
            $this->notifier->notify($user, 'hrms.approval.escalated', $this->payload($approval));
        }

        $approval->update(['escalated_at' => now()]);

        $this->audit->log($approval, 'approval.escalated', null, [
            'due_at' => $approval->due_at?->toIso8601String(),
            'notified' => $recipients->count(),
        ], null);
    }

    /** @return list<User> */
    private function currentApprovers(Approval $approval): array
    {
        $users = collect();

        foreach ($approval->currentStageSteps() as $step) {
            $users = $users->concat($this->usersFor($step));
        }

        return $users->unique('id')->values()->all();
    }

    /** @return iterable<User> */
    private function usersFor(ApprovalStep $step): iterable
    {
        if ($step->approver_user_id !== null) {
            return User::query()->whereKey($step->approver_user_id)->get();
        }

        if ($step->approver_role_id === null) {
            return [];
        }

        return User::query()
            ->whereHas('roles', fn ($q) => $q->whereKey($step->approver_role_id))
            ->get();
    }

    /** @return array<string, mixed> */
    private function payload(Approval $approval): array
    {
        return [
            'approval_id' => $approval->id,
            'subject' => $approval->subject,
            'domain' => $approval->domain,
            'due_at' => $approval->due_at?->toIso8601String(),
        ];
    }
}
