<?php

namespace App\Services\Hrms\Employee;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Employee\EmployeeStatusHistory;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Employee/HRMS — moving a person between employment states.
 *
 * Its own class because the rule and the record have to be written together: a
 * status change without a history row is a silent edit, and a history row
 * without a status change is a lie. Keeping the write, the ledger row and the
 * exit-date stamp in one transaction — and in one file — is what makes that
 * structural rather than a review convention.
 *
 * @see EmployeeService for the public entry points.
 */
class EmployeeStatusTransition
{
    /**
     * Apply a status change: the record, the history row, the exit date.
     *
     * @param  array{effective_date?: string|null, reason?: string|null, note?: string|null}  $options
     *
     * @throws ValidationException when the employee is already in that state
     */
    public function apply(Employee $employee, EmployeeStatus $to, array $options = [], ?User $actor = null): Employee
    {
        $from = $employee->status;

        if ($from === $to) {
            throw ValidationException::withMessages([
                'status' => sprintf('%s is already %s.', $employee->displayName(), $to->label()),
            ]);
        }

        $effectiveDate = $options['effective_date'] ?? null;

        DB::transaction(function () use ($employee, $from, $to, $effectiveDate, $options, $actor) {
            $this->move($employee, $to, $effectiveDate, $options['reason'] ?? null);
            $this->record($employee, $from, $to, $effectiveDate, $options, $actor);
        });

        return $employee->refresh();
    }

    /**
     * Move the record itself.
     *
     * An offboarding status with no date leaves "when did they leave" as the one
     * question nobody can answer, so the date is stamped at the moment of the
     * change rather than left to a follow-up edit that may never arrive — and
     * stamped again on a re-departure, because someone rehired and let go later
     * did not leave on the date of the first episode. The *first* departure keeps
     * its own date and reason in `employee_status_history`, which is what the
     * ledger is for.
     *
     * A move back to an employed status touches neither field: a reactivation
     * must not erase the record of the exit that preceded it.
     */
    private function move(Employee $employee, EmployeeStatus $to, ?string $effectiveDate, ?string $reason): void
    {
        $employee->status = $to;

        if ($to->isOffboarding()) {
            $employee->exit_date = $effectiveDate ? Carbon::parse($effectiveDate) : now();
            $employee->exited_reason = $reason;
        }

        $employee->save();
    }

    /**
     * Write the ledger row for the move.
     */
    private function record(
        Employee $employee,
        EmployeeStatus $from,
        EmployeeStatus $to,
        ?string $effectiveDate,
        array $options,
        ?User $actor,
    ): void {
        EmployeeStatusHistory::create([
            'employee_id' => $employee->id,
            'from_status' => $from,
            'to_status' => $to,
            'effective_date' => $effectiveDate,
            'reason' => $options['reason'] ?? null,
            'note' => $options['note'] ?? null,
            'actor_user_id' => $actor?->id,
        ]);
    }

    /**
     * End an employment, refusing to end it twice.
     *
     * Separate from {@see self::apply()} because termination is the transition
     * a final settlement keys off: it forces the `terminated` target instead of
     * accepting one, and a second call is an error rather than a second exit
     * date on the same record.
     *
     * @throws ValidationException 422 when the employee has already left
     */
    public function terminate(Employee $employee, ?string $reason = null, ?string $note = null, ?User $actor = null): Employee
    {
        if ($employee->status->isOffboarding()) {
            throw ValidationException::withMessages([
                'status' => sprintf('%s has already left.', $employee->displayName()),
            ]);
        }

        return $this->apply($employee, EmployeeStatus::Terminated, [
            'effective_date' => now()->toDateString(),
            'reason' => $reason,
            'note' => $note,
        ], $actor);
    }
}
