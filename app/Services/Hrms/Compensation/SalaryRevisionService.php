<?php

namespace App\Services\Hrms\Compensation;

use App\Enums\Hrms\DocumentSource;
use App\Enums\Hrms\DocumentStatus;
use App\Enums\Hrms\DocumentVisibility;
use App\Enums\Hrms\RevisionStatus;
use App\Models\Hrms\Document\DocumentType;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Payroll\EmployeeSalaryStructure;
use App\Models\Hrms\Payroll\SalaryRevision;
use App\Models\Hrms\Shared\HrmsSetting;
use App\Models\User;
use App\Services\Hrms\Approval\ChainBuilder;
use App\Services\Hrms\Shared\ApprovalService;
use App\Services\HrmsAuditLogger;
use App\Support\Hrms\Money;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Compensation/HRMS — raises and their letters.
 *
 * The revision half of compensation (templates and assignments live in
 * `CompensationService` — the P5 split rule). Below the configured
 * threshold a revision approves itself and applies immediately — small
 * corrections should not queue behind a manager. At or above it (and
 * always for cuts, which never clear a positive threshold), the revision
 * opens a manager-step chain and waits: applying lands here, never in
 * `revise()`.
 */
class SalaryRevisionService
{
    public function __construct(
        private readonly CompensationService $compensation,
        private readonly ApprovalService $approvals,
        private readonly ChainBuilder $chains,
        private readonly TenantContext $context,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /**
     * Raise (or cut) a person's CTC from a date.
     *
     * @throws ValidationException without a current assignment to revise from
     */
    public function revise(
        Employee $employee,
        string $toCtc,
        Carbon|string $effectiveFrom,
        ?string $reason = null,
        ?User $actor = null,
    ): SalaryRevision {
        $current = $this->currentAssignment($employee);

        if ($current === null) {
            throw ValidationException::withMessages(['form' => 'There is no current structure to revise from — assign first.']);
        }

        $from = Money::fromDecimal($current->ctc_annual, $current->structure->currency ?? 'INR');
        $to = Money::fromDecimal($toCtc, $from->currency);

        if ($to->isNegative() || $to->isZero()) {
            throw ValidationException::withMessages(['to_ctc' => 'A CTC prices positive money.']);
        }

        // Exact integer comparison, never floats: (to − from) × 100 >=
        // from × threshold. Cuts always need eyes (a negative diff can
        // never clear a positive threshold, so it is not even computed),
        // and a zero base cannot divide — both route through approval.
        $threshold = (string) (HrmsSetting::current()->setting('compensation.revision_approval_threshold_percent', 10) ?? 10);
        $diff = $to->sub($from)->minor;
        $needsApproval = $diff < 0
            || $from->isZero()
            || bccomp(bcdiv(bcmul((string) $diff, '100', 4), (string) $from->minor, 2), $threshold, 2) >= 0;
        $change = $from->isZero() ? '0.00' : bcdiv(bcmul((string) $diff, '100', 4), (string) $from->minor, 2);

        return DB::transaction(function () use ($employee, $from, $to, $change, $needsApproval, $effectiveFrom, $reason, $actor): SalaryRevision {
            $revision = SalaryRevision::create([
                'employee_id' => $employee->id,
                'from_ctc' => $from->toDecimal(),
                'to_ctc' => $to->toDecimal(),
                'change_percent' => $change,
                'effective_from' => $effectiveFrom instanceof Carbon ? $effectiveFrom->toDateString() : (string) $effectiveFrom,
                'reason' => $reason,
                'status' => $needsApproval ? RevisionStatus::Draft->value : RevisionStatus::Approved->value,
            ]);

            if ($needsApproval) {
                $approval = $this->approvals->request(
                    $this->chains->stepsFor('salary_revision', $employee, ['percent' => $change]),
                    $revision,
                    'compensation.revise',
                    'Salary revision',
                    ['from_ctc' => $from->toDecimal(), 'to_ctc' => $to->toDecimal()],
                    $actor,
                    $employee->id,
                    'salary_revision',
                );

                $revision->update(['approval_id' => $approval->id]);
                $this->audit->log($revision, 'compensation.revision_raised', null, $this->snapshot($revision), $actor);

                return $revision->refresh();
            }

            $this->audit->log($revision, 'compensation.revision_raised', null, $this->snapshot($revision), $actor);

            return $this->apply($revision->refresh(), $actor);
        });
    }

    /**
     * Apply an approved revision: price the new assignment through the
     * shared assignment path (close the live row, materialise the new
     * one), stamp the revision applied, and file the letter.
     *
     * Drafts apply only once their chain has resolved approved — applying
     * an open chain would pay an unapproved raise.
     *
     * @throws ValidationException unless approved (or draft with a resolved chain)
     */
    public function apply(SalaryRevision $revision, ?User $actor = null): SalaryRevision
    {
        if ($revision->status === RevisionStatus::Draft) {
            if ($revision->approval === null || $revision->approval->fresh()->status->value !== 'approved') {
                throw ValidationException::withMessages(['form' => 'Resolve the approval chain before applying.']);
            }

            $revision->update([
                'status' => RevisionStatus::Approved->value,
                'approved_by_user_id' => $revision->approval->resolved_by_user_id,
                'approved_at' => now(),
            ]);
        }

        if ($revision->status !== RevisionStatus::Approved) {
            throw ValidationException::withMessages(['form' => 'Only an approved revision can be applied.']);
        }

        return DB::transaction(function () use ($revision, $actor): SalaryRevision {
            $employee = $revision->employee;
            $current = $this->currentAssignment($employee);

            if ($current === null) {
                throw ValidationException::withMessages(['form' => 'There is no current structure to revise from — assign first.']);
            }

            $this->compensation->assign(
                $employee,
                $current->structure,
                (string) $revision->to_ctc,
                (string) $revision->effective_from,
                $revision->reason,
                $actor,
            );

            $letter = $this->materialiseLetter($revision->refresh(), $actor);
            $revision->update([
                'status' => RevisionStatus::Applied->value,
                'approved_by_user_id' => $actor?->id,
                'approved_at' => now(),
                'letter_document_id' => $letter->id,
            ]);

            $this->audit->log($revision, 'compensation.revision_applied', ['status' => 'approved'], $this->snapshot($revision->refresh()), $actor);

            return $revision->refresh();
        });
    }

    /**
     * Materialise the revision letter as a document row with real bytes: a
     * plain-text letter on the local disk next to every other HR file, so
     * the number and its paper never part. The revision-letter type is the
     * seeded starter; without it there is nothing truthful to file under.
     */
    private function materialiseLetter(SalaryRevision $revision, ?User $actor): EmployeeDocument
    {
        $type = DocumentType::query()->where('slug', 'revision_letter')->first();

        if ($type === null || ! $type->is_active) {
            throw ValidationException::withMessages(['form' => 'There is no active revision-letter document type to file under.']);
        }

        $tenantId = $this->context->currentId();

        if ($tenantId === null) {
            throw ValidationException::withMessages(['form' => 'Letters need an active tenant before anything can be stored.']);
        }

        $employee = $revision->employee;
        $content = implode("\n", [
            'Salary revision letter',
            "Employee: {$employee->name} ({$employee->employee_code})",
            "From CTC: {$revision->from_ctc} to {$revision->to_ctc}",
            'Effective: '.Carbon::parse((string) $revision->effective_from)->toDateString(),
            'Issued: '.today()->toDateString(),
        ])."\n";

        $path = "hrms/{$tenantId}/{$employee->id}/".((string) Str::uuid()).'.txt';
        Storage::disk('local')->put($path, $content);

        return EmployeeDocument::create([
            'employee_id' => $employee->id,
            'document_type_id' => $type->id,
            'title' => "Salary revision letter — {$employee->name}",
            'file_disk' => 'local',
            'file_path' => $path,
            'original_name' => "revision-letter-{$revision->id}.txt",
            'mime' => 'text/plain',
            'size' => strlen($content),
            'status' => DocumentStatus::Verified->value,
            'visibility' => DocumentVisibility::Employee->value,
            'source' => DocumentSource::Hr->value,
            'created_by' => $actor?->id,
        ]);
    }

    private function currentAssignment(Employee $employee): ?EmployeeSalaryStructure
    {
        return EmployeeSalaryStructure::query()
            ->where('employee_id', $employee->id)
            ->where('is_current', true)
            ->first();
    }

    /** @return array<string, mixed> */
    private function snapshot(SalaryRevision $revision): array
    {
        // Identifiers and state only — amounts never enter audit data
        // (D2.17.8 bans salary figures from logs; the revision row itself
        // carries the numbers for HR to query).
        return [
            'employee_id' => $revision->employee_id,
            'status' => $revision->status->value,
            'approval_id' => $revision->approval_id,
        ];
    }
}
