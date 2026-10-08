<?php

namespace App\Services\Hrms\Statutory;

use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Statutory\StatutoryDeclaration;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Statutory/HRMS — exemption claims and their small lifecycle.
 *
 * Its own class because `StatutoryService` is at 259 lines and this is a
 * second concern (claims, not projections) — the P5 split rule. A claim is
 * filed as a draft, submitted, then verified or rejected; only verified
 * rows reduce tax, so the transitions are the control, and every one
 * audits identifiers (section, states) but never the amount.
 */
class StatutoryDeclarationService
{
    public function __construct(private readonly HrmsAuditLogger $audit) {}

    /**
     * File a claim as a draft. A proof file must belong to the employee —
     * evidence for somebody else's claim is either a mistake or a lie, and
     * both are refused rather than stored.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException on a foreign proof file
     */
    public function file(Employee $employee, array $data, ?User $actor = null): StatutoryDeclaration
    {
        if (! empty($data['proof_document_id'])) {
            $proof = EmployeeDocument::find($data['proof_document_id']);

            if ($proof === null || (int) $proof->employee_id !== (int) $employee->id) {
                throw ValidationException::withMessages(['proof_document_id' => 'That file does not belong to this employee.']);
            }
        }

        $declaration = StatutoryDeclaration::create([
            'employee_id' => $employee->id,
            'fiscal_year' => $data['fiscal_year'],
            'section' => $data['section'],
            'declared_amount' => $data['declared_amount'],
            'proof_document_id' => $data['proof_document_id'] ?? null,
            'status' => 'draft',
        ]);

        $this->audit->log($declaration, 'statutory.declaration_filed', null, $this->snapshot($declaration), $actor);

        return $declaration->refresh();
    }

    /**
     * @throws ValidationException outside draft
     */
    public function submit(StatutoryDeclaration $declaration, ?User $actor = null): StatutoryDeclaration
    {
        return $this->transition($declaration, 'draft', [
            'status' => 'submitted',
            'submitted_at' => now(),
        ], 'statutory.declaration_submitted', $actor);
    }

    /**
     * @throws ValidationException outside submitted
     */
    public function verify(StatutoryDeclaration $declaration, ?User $actor = null): StatutoryDeclaration
    {
        return $this->transition($declaration, 'submitted', [
            'status' => 'verified',
            'verified_at' => now(),
            'verified_by_user_id' => $actor?->id,
        ], 'statutory.declaration_verified', $actor);
    }

    /**
     * @throws ValidationException outside submitted
     */
    public function reject(StatutoryDeclaration $declaration, ?User $actor = null): StatutoryDeclaration
    {
        return $this->transition($declaration, 'submitted', [
            'status' => 'rejected',
        ], 'statutory.declaration_rejected', $actor);
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException on any other state
     */
    private function transition(
        StatutoryDeclaration $declaration,
        string $from,
        array $attributes,
        string $action,
        ?User $actor,
    ): StatutoryDeclaration {
        if ($declaration->status !== $from) {
            throw ValidationException::withMessages(['form' => "Only a {$from} claim can move here."]);
        }

        return DB::transaction(function () use ($declaration, $attributes, $action, $actor): StatutoryDeclaration {
            $before = $this->snapshot($declaration);
            $declaration->update($attributes);

            $this->audit->log($declaration->refresh(), $action, $before, $this->snapshot($declaration->refresh()), $actor);

            return $declaration->refresh();
        });
    }

    /**
     * Identifiers and states only: the section names the law, the amount
     * never enters the ledger (D2.17.8).
     *
     * @return array<string, mixed>
     */
    private function snapshot(StatutoryDeclaration $declaration): array
    {
        return [
            'employee_id' => $declaration->employee_id,
            'fiscal_year' => $declaration->fiscal_year,
            'section' => $declaration->section,
            'status' => $declaration->status,
        ];
    }
}
