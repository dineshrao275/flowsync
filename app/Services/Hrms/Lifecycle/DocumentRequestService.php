<?php

namespace App\Services\Hrms\Lifecycle;

use App\Enums\Hrms\DocumentRequestSource;
use App\Enums\Hrms\DocumentRequestStatus;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Lifecycle\DocumentRequest;
use App\Models\Hrms\Lifecycle\OffboardingCase;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lifecycle/HRMS — the document ask and its answer.
 *
 * Shared by onboarding and offboarding because a request is the same thing
 * wherever it is raised from: “we need this file from this person”, tracked
 * from pending through submitted to accepted. The case that raised it is
 * carried on `case_type`/`case_id`, and both case services delegate here
 * rather than each owning a copy of the state machine.
 */
class DocumentRequestService
{
    public function __construct(private readonly HrmsAuditLogger $audit) {}

    /**
     * Raise one ask, optionally from a case.
     *
     * @param  array{title: string, document_type_id?: int|null, due_date?: string|null, note?: string|null}  $attributes
     *
     * @throws ValidationException on a missing title
     */
    public function requestFor(
        Employee $employee,
        array $attributes,
        DocumentRequestSource $source = DocumentRequestSource::Hr,
        ?Model $case = null,
        ?User $actor = null,
    ): DocumentRequest {
        $title = trim((string) ($attributes['title'] ?? ''));

        if ($title === '' || mb_strlen($title) > 255) {
            throw ValidationException::withMessages(['title' => 'A request needs a short title the employee recognises.']);
        }

        return DB::transaction(function () use ($employee, $attributes, $title, $source, $case, $actor): DocumentRequest {
            $request = DocumentRequest::create([
                'employee_id' => $employee->id,
                'document_type_id' => $attributes['document_type_id'] ?? null,
                'title' => $title,
                'due_date' => $attributes['due_date'] ?? null,
                // Explicit, not the DB default: the in-memory model would
                // otherwise carry null until its first refresh, and the audit
                // snapshot below reads it before that happens.
                'status' => DocumentRequestStatus::Pending->value,
                'source' => $source->value,
                'case_type' => $case === null ? null : $this->caseType($case),
                'case_id' => $case?->getKey(),
                'note' => $attributes['note'] ?? null,
            ]);
            $this->audit->log($request, 'document.requested', null, $this->snapshot($request), $actor);

            return $request->refresh();
        });
    }

    /**
     * Attach the submitted file to the ask.
     *
     * @throws ValidationException on a closed request or someone else’s file
     */
    public function submit(DocumentRequest $request, EmployeeDocument $document, ?User $actor = null): DocumentRequest
    {
        $this->requireTransition($request, DocumentRequestStatus::Submitted);

        if ((int) $document->employee_id !== (int) $request->employee_id) {
            throw ValidationException::withMessages(['document_id' => 'That file belongs to someone else.']);
        }

        $before = ['status' => $request->status->value];

        return DB::transaction(function () use ($request, $document, $actor, $before): DocumentRequest {
            $request->update([
                'document_id' => $document->id,
                'status' => DocumentRequestStatus::Submitted->value,
            ]);
            $this->audit->log($request, 'document.submitted', $before, $this->snapshot($request->refresh()), $actor);

            return $request->refresh();
        });
    }

    /**
     * Accept the submitted file as evidence.
     *
     * @throws ValidationException unless a file is actually attached
     */
    public function accept(DocumentRequest $request, ?User $actor = null): DocumentRequest
    {
        $this->requireTransition($request, DocumentRequestStatus::Accepted);

        if ($request->document_id === null) {
            throw ValidationException::withMessages(['form' => 'There is no file to accept yet.']);
        }

        $before = ['status' => $request->status->value];

        return DB::transaction(function () use ($request, $actor, $before): DocumentRequest {
            $request->update(['status' => DocumentRequestStatus::Accepted->value]);
            $this->audit->log($request, 'document.accepted', $before, $this->snapshot($request->refresh()), $actor);

            return $request->refresh();
        });
    }

    /**
     * Decline the file and reopen the loop: the employee uploads again.
     *
     * @throws ValidationException on an empty reason
     */
    public function reject(DocumentRequest $request, string $reason, ?User $actor = null): DocumentRequest
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['note' => 'A rejection needs a reason the employee can act on.']);
        }

        $this->requireTransition($request, DocumentRequestStatus::Rejected);
        $before = ['status' => $request->status->value];

        return DB::transaction(function () use ($request, $reason, $actor, $before): DocumentRequest {
            $request->update([
                'status' => DocumentRequestStatus::Rejected->value,
                'note' => $reason,
            ]);
            $this->audit->log($request, 'document.rejected', $before, $this->snapshot($request->refresh()), $actor);

            return $request->refresh();
        });
    }

    /**
     * Decide no file is needed after all, with the reason recorded.
     *
     * @throws ValidationException on an empty reason
     */
    public function waive(DocumentRequest $request, string $reason, ?User $actor = null): DocumentRequest
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['note' => 'A waived ask still needs its reason on record.']);
        }

        $this->requireTransition($request, DocumentRequestStatus::Waived);
        $before = ['status' => $request->status->value];

        return DB::transaction(function () use ($request, $reason, $actor, $before): DocumentRequest {
            $request->update([
                'status' => DocumentRequestStatus::Waived->value,
                'note' => $reason,
            ]);
            $this->audit->log($request, 'document.request_waived', $before, $this->snapshot($request->refresh()), $actor);

            return $request->refresh();
        });
    }

    /**
     * Every ask raised from one case, oldest first.
     *
     * @return Collection<int, DocumentRequest>
     */
    public function forCase(Model $case): Collection
    {
        return DocumentRequest::query()
            ->where('case_type', $this->caseType($case))
            ->where('case_id', $case->getKey())
            ->orderBy('id')
            ->get();
    }

    private function caseType(Model $case): string
    {
        return $case instanceof OffboardingCase ? 'offboarding' : 'onboarding';
    }

    private function requireTransition(DocumentRequest $request, DocumentRequestStatus $to): void
    {
        if (! $request->status->canTransitionTo($to)) {
            throw ValidationException::withMessages(['form' => "A {$request->status->value} request cannot become {$to->value}."]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(DocumentRequest $request): array
    {
        return [
            'employee_id' => $request->employee_id,
            'document_type_id' => $request->document_type_id,
            'document_id' => $request->document_id,
            'status' => $request->status->value,
        ];
    }
}
