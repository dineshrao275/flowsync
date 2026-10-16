<?php

namespace App\Services\Hrms\Lifecycle;

use App\Enums\Hrms\ApprovalStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Employee\EmployeeChange;
use App\Models\User;
use App\Services\Hrms\Approval\ChainBuilder;
use App\Services\Hrms\Employee\EmployeeService;
use App\Services\Hrms\Shared\ApprovalService;
use App\Services\HrmsAuditLogger;
use App\Support\Hrms\Auditable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Lifecycle/HRMS — transfers, promotions and confirmations.
 *
 * A change is requested with an effective date, decided through the approval engine, and applied to the
 * employee record on that date (immediately if it is already due). What a change may touch is a short
 * whitelist — department, designation, location, manager, employment type — so it cannot be used to edit
 * personal data or pay. Applying goes through EmployeeService, so every write is audited like any other edit.
 */
class EmployeeChangeService
{
    private const FIELDS = ['department_id', 'designation_id', 'designation', 'location_id', 'manager_id', 'employment_type_id'];

    private const REFERENCES = [
        'department_id' => 'departments', 'designation_id' => 'designations', 'location_id' => 'locations',
        'manager_id' => 'employees', 'employment_type_id' => 'employment_types',
    ];

    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly ChainBuilder $chains,
        private readonly EmployeeService $employees,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $changes
     *
     * @throws ValidationException on an empty or unknown change, a bad reference, or an overlapping open request
     */
    public function request(Employee $employee, string $type, Carbon|string $effective, array $changes, ?string $reason, User $actor): EmployeeChange
    {
        $date = Carbon::parse($effective)->startOfDay();
        $clean = $this->cleanChanges($type, $changes, $employee);

        if (EmployeeChange::query()->where('employee_id', $employee->id)->where('status', EmployeeChange::PENDING)->exists()) {
            throw ValidationException::withMessages(['form' => __('This person already has a change waiting for a decision.')]);
        }

        return DB::transaction(function () use ($employee, $type, $date, $clean, $reason, $actor): EmployeeChange {
            $change = EmployeeChange::create([
                'employee_id' => $employee->id,
                'type' => $type,
                'effective_date' => $date->toDateString(),
                'changes' => $clean,
                'reason' => $reason !== null ? trim($reason) : null,
                'status' => EmployeeChange::PENDING,
                'requested_by_user_id' => $actor->id,
            ]);

            $approval = $this->approvals->request(
                $this->chains->stepsFor('employee_change', $employee),
                $change,
                'employee.change',
                ucfirst($type),
                ['type' => $type, 'effective_date' => $date->toDateString()],
                $actor,
                $employee->id,
                'employee_change',
            );
            $change->update(['approval_id' => $approval->id]);
            $this->audit->log($change, 'employee_change.requested', null, $this->snapshot($change->refresh()), $actor);

            return $approval->status === ApprovalStatus::Approved ? $this->approved($change, $actor, null) : $change->refresh();
        });
    }

    public function approve(EmployeeChange $change, User $actor, ?string $note = null): EmployeeChange
    {
        $this->requireOpen($change);

        $approval = $this->approvals->approve($change->approval, $actor, $note);

        return $approval->status === ApprovalStatus::Approved ? $this->approved($change, $actor, $note) : $change->refresh();
    }

    public function reject(EmployeeChange $change, User $actor, string $note): EmployeeChange
    {
        $this->requireOpen($change);

        if (trim($note) === '') {
            throw ValidationException::withMessages(['decision_note' => __('A rejection needs a reason the requester can act on.')]);
        }

        $this->approvals->reject($change->approval, $actor, $note);
        $change->update(['status' => EmployeeChange::REJECTED, 'decided_at' => now(), 'decided_by_user_id' => $actor->id, 'decision_note' => mb_substr(trim($note), 0, 500)]);
        $this->audit->log($change, 'employee_change.rejected', ['status' => 'pending'], $this->snapshot($change->refresh()), $actor);

        return $change->refresh();
    }

    public function withdraw(EmployeeChange $change, User $actor): EmployeeChange
    {
        $this->requireOpen($change);

        $this->approvals->cancel($change->approval, $actor);
        $change->update(['status' => EmployeeChange::WITHDRAWN, 'decided_at' => now(), 'decided_by_user_id' => $actor->id]);
        $this->audit->log($change, 'employee_change.withdrawn', ['status' => 'pending'], $this->snapshot($change->refresh()), $actor);

        return $change->refresh();
    }

    /** Apply every approved change whose date has arrived; returns how many were applied. */
    public function applyDue(): int
    {
        if (! Schema::hasTable('employee_changes')) {
            return 0;
        }

        $applied = 0;

        EmployeeChange::query()->where('status', EmployeeChange::APPROVED)->whereDate('effective_date', '<=', now()->toDateString())->orderBy('effective_date')->get()
            ->each(function (EmployeeChange $change) use (&$applied): void {
                $this->apply($change);
                $applied++;
            });

        return $applied;
    }

    private function approved(EmployeeChange $change, ?User $actor, ?string $note): EmployeeChange
    {
        $change->update([
            'status' => EmployeeChange::APPROVED,
            'decided_at' => now(),
            'decided_by_user_id' => $actor?->id,
            'decision_note' => $note !== null ? mb_substr(trim($note), 0, 500) : null,
        ]);
        $this->audit->log($change, 'employee_change.approved', ['status' => 'pending'], $this->snapshot($change->refresh()), $actor);

        // Due now? Apply it now; otherwise the daily command picks it up on its date.
        return $change->effective_date->lte(now()->startOfDay()) ? $this->apply($change) : $change->refresh();
    }

    private function apply(EmployeeChange $change): EmployeeChange
    {
        return DB::transaction(function () use ($change): EmployeeChange {
            $employee = $change->employee;
            $changes = $change->changes ?? [];
            $actor = $change->decided_by_user_id ? User::find($change->decided_by_user_id) : null;

            $profile = array_diff_key($changes, ['manager_id' => true]);
            if ($change->type === 'confirmation') {
                $profile['confirmation_date'] = $change->effective_date->toDateString();
            }

            if ($profile !== []) {
                $employee = $this->employees->update($employee, $profile, $actor);
            }

            if (array_key_exists('manager_id', $changes)) {
                $this->employees->assignManager($employee, $changes['manager_id'] ? Employee::find($changes['manager_id']) : null, $actor);
            }

            $change->update(['status' => EmployeeChange::APPLIED, 'applied_at' => now()]);
            $this->audit->log($change, 'employee_change.applied', ['status' => 'approved'], $this->snapshot($change->refresh()), $actor);

            return $change->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function cleanChanges(string $type, array $changes, Employee $employee): array
    {
        if (! in_array($type, EmployeeChange::TYPES, true)) {
            throw ValidationException::withMessages(['type' => __('Choose transfer, promotion or confirmation.')]);
        }

        $clean = [];
        foreach (array_intersect_key($changes, array_flip(self::FIELDS)) as $field => $value) {
            if ($value === '' || $value === null) {
                if ($field === 'manager_id') {
                    $clean[$field] = null; // "no manager" is a legitimate target
                }

                continue;
            }

            if (isset(self::REFERENCES[$field]) && ! DB::table(self::REFERENCES[$field])->where('id', (int) $value)->exists()) {
                throw ValidationException::withMessages(["changes.$field" => __('That choice no longer exists.')]);
            }

            if ($field === 'manager_id' && (int) $value === $employee->id) {
                throw ValidationException::withMessages(['changes.manager_id' => __('Someone cannot report to themself.')]);
            }

            $clean[$field] = in_array($field, ['designation'], true) ? (string) $value : (int) $value;
        }

        if ($clean === [] && $type !== 'confirmation') {
            throw ValidationException::withMessages(['changes' => __('Choose at least one thing to change.')]);
        }

        return $clean;
    }

    private function requireOpen(EmployeeChange $change): void
    {
        if (! $change->isOpen()) {
            throw ValidationException::withMessages(['form' => __('This change has already been decided.')]);
        }

        if ($change->approval === null) {
            throw ValidationException::withMessages(['form' => __('This change has no approval chain.')]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(EmployeeChange $change): array
    {
        return Auditable::snapshot($change, ['employee_id', 'type', 'effective_date', 'status', 'approval_id']);
    }
}
