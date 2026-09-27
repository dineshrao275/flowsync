<?php

namespace App\Services\Hrms\Document;

use App\Enums\Hrms\DocumentStatus;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Document/HRMS — the lifecycle of a stored row.
 *
 * Review, expiry and deletion are one concern because they share the only
 * question this context asks of a status: what may it become next. The file
 * itself is removed on delete while the row is only hidden, so “what did we
 * hold on this person” stays answerable after the bytes are gone.
 */
class DocumentLifecycle
{
    public function __construct(private readonly HrmsAuditLogger $audit) {}

    /**
     * Mark a reviewed document as evidence.
     *
     * @throws ValidationException when the row is not awaiting review
     */
    public function verify(EmployeeDocument $document, ?User $actor = null): EmployeeDocument
    {
        $this->requireTransition($document, DocumentStatus::Verified);
        $before = ['status' => $document->status->value];

        return DB::transaction(function () use ($document, $actor, $before): EmployeeDocument {
            $document->update([
                'status' => DocumentStatus::Verified->value,
                'verified_at' => now(),
                'verified_by_user_id' => $actor?->id,
                'rejection_reason' => null,
            ]);
            $this->audit->log($document, 'document.verified', $before, ['status' => DocumentStatus::Verified->value], $actor);

            return $document->refresh();
        });
    }

    /**
     * Refuse a document, with the reason HR gives the employee.
     *
     * @throws ValidationException on an empty reason or a closed row
     */
    public function reject(EmployeeDocument $document, string $reason, ?User $actor = null): EmployeeDocument
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 255) {
            throw ValidationException::withMessages(['rejection_reason' => 'A rejection needs a short reason the employee can act on.']);
        }

        $this->requireTransition($document, DocumentStatus::Rejected);
        $before = ['status' => $document->status->value];

        return DB::transaction(function () use ($document, $reason, $actor, $before): EmployeeDocument {
            $document->update([
                'status' => DocumentStatus::Rejected->value,
                'verified_at' => null,
                'verified_by_user_id' => null,
                'rejection_reason' => $reason,
            ]);
            $this->audit->log($document, 'document.rejected', $before, ['status' => DocumentStatus::Rejected->value], $actor);

            return $document->refresh();
        });
    }

    /**
     * Close a live document whose expiry has passed.
     *
     * @throws ValidationException on an already-closed row
     */
    public function markExpired(EmployeeDocument $document, ?User $actor = null): EmployeeDocument
    {
        $this->requireTransition($document, DocumentStatus::Expired);
        $before = ['status' => $document->status->value];

        return DB::transaction(function () use ($document, $actor, $before): EmployeeDocument {
            $document->update(['status' => DocumentStatus::Expired->value]);
            $this->audit->log($document, 'document.expired', $before, ['status' => DocumentStatus::Expired->value], $actor);

            return $document->refresh();
        });
    }

    /**
     * Close every live document past its expiry, for the expiry command.
     *
     * @return Collection<int, EmployeeDocument>
     */
    public function expireDue(?Carbon $asOf = null): Collection
    {
        $due = EmployeeDocument::query()
            ->awaitingAction()
            ->expiringBy($asOf ?? today())
            ->orderBy('expires_at')
            ->orderBy('id')
            ->get();

        foreach ($due as $document) {
            $this->markExpired($document);
        }

        return $due;
    }

    /**
     * Live documents expiring within the next `$days` days.
     *
     * @return Collection<int, EmployeeDocument>
     *
     * @throws ValidationException on a negative window
     */
    public function expireSoon(Employee $employee, int $days): Collection
    {
        if ($days < 0) {
            throw ValidationException::withMessages(['days' => 'The warning window cannot look backwards.']);
        }

        return EmployeeDocument::query()
            ->where('employee_id', $employee->id)
            ->expiringBy(now()->addDays($days))
            ->orderBy('expires_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Remove the file and hide the row. The bytes go away; the ledger entry
     * stays, so “what did we hold on this person” remains answerable.
     */
    public function delete(EmployeeDocument $document, ?User $actor = null): void
    {
        abort_if($document->trashed(), 404);
        $path = $document->file_path;
        $snapshot = $this->snapshot($document);

        if (Storage::disk($document->file_disk)->exists($path)
            && ! Storage::disk($document->file_disk)->delete($path)
        ) {
            throw ValidationException::withMessages(['form' => 'The file could not be removed, so the row was left alone.']);
        }

        $document->delete();
        $this->audit->log($document, 'document.deleted', null, $snapshot, $actor);
    }

    private function requireTransition(EmployeeDocument $document, DocumentStatus $to): void
    {
        if (! $document->status->canTransitionTo($to)) {
            throw ValidationException::withMessages(['form' => "A {$document->status->value} document cannot become {$to->value}."]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(EmployeeDocument $document): array
    {
        return [
            'employee_id' => $document->employee_id,
            'document_type_id' => $document->document_type_id,
            'status' => $document->status->value,
        ];
    }
}
