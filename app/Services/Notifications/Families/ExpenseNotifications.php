<?php

namespace App\Services\Notifications\Families;

use App\Models\Hrms\Expense\ExpenseClaim;
use App\Models\Hrms\Payroll\PayrollRun;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifications\BaseNotificationFamily;

/**
 * The `hrms.expense` notification family (moved verbatim out of NotificationService, P1.6).
 */
class ExpenseNotifications extends BaseNotificationFamily
{
    public function family(): string
    {
        return 'hrms.expense';
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

        $recipients = $this->usersById($recipientIds);
        foreach ($recipientIds as $recipientId) {
            $recipient = $recipients->get((int) $recipientId);

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
}
