<?php

namespace App\Services\Hrms\Payroll;

use App\Enums\Hrms\PayrollRunStatus;
use App\Enums\Hrms\PayslipStatus;
use App\Models\Hrms\Payroll\PayrollRun;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payroll/HRMS — the run state machine after calculation.
 *
 * Split from {@see PayrollService} for the 300-line ceiling, not for reuse:
 * review → approved → processing → paid → locked, each transition audited,
 * publish additionally notifying every employee (amounts never travel in
 * notification data — the payslip carries the numbers behind its own access
 * log). A wrong-state call 422s naming the run, and every transition runs
 * in a transaction so a half-flipped run cannot exist.
 */
class PayrollLifecycle
{
    public function __construct(
        private readonly HrmsAuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @throws ValidationException outside review
     */
    public function approve(PayrollRun $run, ?User $actor = null): PayrollRun
    {
        $this->require($run, PayrollRunStatus::Review, 'Only a run in review can be approved.');

        return DB::transaction(function () use ($run, $actor): PayrollRun {
            $run->update([
                'status' => PayrollRunStatus::Approved,
                'approved_by_user_id' => $actor?->id,
                'approved_at' => now(),
            ]);

            $this->audit->log($run->refresh(), 'payroll.approved', ['status' => 'review'], ['status' => 'approved'], $actor);

            return $run->refresh();
        });
    }

    /**
     * Release payslips to employees and tell them. The notification count
     * rides the audit row so a publish that reached nobody reads as one.
     *
     * @throws ValidationException outside approved
     */
    public function publish(PayrollRun $run, ?User $actor = null): PayrollRun
    {
        $this->require($run, PayrollRunStatus::Approved, 'Only an approved run can be published.');

        return DB::transaction(function () use ($run, $actor): PayrollRun {
            $run->update(['status' => PayrollRunStatus::Processing]);

            $run->payslips()->where('status', PayslipStatus::Draft->value)->update([
                'status' => PayslipStatus::Published->value,
                'published_at' => now(),
            ]);

            $sent = $this->notifications->payrollPublished($run->refresh(), $actor);

            $this->audit->log($run->refresh(), 'payroll.published', ['status' => 'approved'], [
                'status' => 'processing',
                'notified' => count($sent),
            ], $actor);

            return $run->refresh();
        });
    }

    /**
     * @throws ValidationException outside processing
     */
    public function markPaid(PayrollRun $run, ?User $actor = null): PayrollRun
    {
        $this->require($run, PayrollRunStatus::Processing, 'Only a published run can be marked paid.');

        return DB::transaction(function () use ($run, $actor): PayrollRun {
            $run->update([
                'status' => PayrollRunStatus::Paid,
                'processed_at' => now(),
            ]);

            $run->payslips()->update(['status' => PayslipStatus::Paid->value]);

            $this->audit->log($run->refresh(), 'payroll.paid', ['status' => 'processing'], ['status' => 'paid'], $actor);

            return $run->refresh();
        });
    }

    /**
     * Seal history: the run and every payslip stamp `locked_at`, and from
     * here the engine refuses the run entirely.
     *
     * @throws ValidationException outside paid
     */
    public function lock(PayrollRun $run, ?User $actor = null): PayrollRun
    {
        $this->require($run, PayrollRunStatus::Paid, 'Only a paid run can be locked.');

        return DB::transaction(function () use ($run, $actor): PayrollRun {
            $run->update([
                'status' => PayrollRunStatus::Locked,
                'locked_at' => now(),
            ]);

            $run->payslips()->update(['locked_at' => now()]);

            $this->audit->log($run->refresh(), 'payroll.locked', ['status' => 'paid'], ['status' => 'locked'], $actor);

            return $run->refresh();
        });
    }

    /**
     * Take a run back to review so a mistake can be corrected. Allowed from approved, published
     * (processing) and paid; a locked run is sealed history and never reopens. Payslips that were
     * released go back to draft (employees stop seeing them until the run is published again) and
     * the run's approval and processing stamps are cleared — it has to be approved afresh. The
     * reason and the state it was reopened from ride the audit row.
     *
     * @throws ValidationException when locked, not yet approved, or no reason is given
     */
    public function reopen(PayrollRun $run, string $reason, ?User $actor = null): PayrollRun
    {
        if ($run->status === PayrollRunStatus::Locked) {
            throw ValidationException::withMessages(['run' => __('Locked runs are sealed history.')]);
        }

        if (! in_array($run->status, [PayrollRunStatus::Approved, PayrollRunStatus::Processing, PayrollRunStatus::Paid], true)) {
            throw ValidationException::withMessages(['run' => __('A run can be reopened only after approval and before it is locked.')]);
        }

        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['reason' => __('Say why the run is being reopened (at least 10 characters).')]);
        }

        return DB::transaction(function () use ($run, $reason, $actor): PayrollRun {
            $from = $run->status->value;

            $run->payslips()->whereIn('status', [PayslipStatus::Published->value, PayslipStatus::Paid->value, PayslipStatus::Disputed->value])
                ->update(['status' => PayslipStatus::Draft->value, 'published_at' => null]);

            $run->update([
                'status' => PayrollRunStatus::Review,
                'approved_by_user_id' => null,
                'approved_at' => null,
                'processed_at' => null,
            ]);

            $this->audit->log($run->refresh(), 'payroll.reopened', ['status' => $from], [
                'status' => 'review',
                'reason' => trim($reason),
            ], $actor);

            return $run->refresh();
        });
    }

    /**
     * @throws ValidationException on any other state
     */
    private function require(PayrollRun $run, PayrollRunStatus $expected, string $message): void
    {
        if ($run->status !== $expected) {
            throw ValidationException::withMessages(['run' => $message]);
        }
    }
}
