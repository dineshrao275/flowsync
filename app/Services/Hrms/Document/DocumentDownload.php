<?php

namespace App\Services\Hrms\Document;

use App\Enums\Hrms\DataAccessAction;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use App\Support\TenantContext;
use App\Support\TenantDatabaseManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Document/HRMS — serving one employee document from a signed link.
 *
 * Its own class because a session-free download has its own rules: the lookup
 * must happen on the tenant database even though the request starts on the
 * central one, and a confidential file additionally needs the reader named in
 * the URL to hold `hrms.documents.view_sensitive` right now.
 */
class DocumentDownload
{
    public function __construct(
        private readonly HrmsAuditLogger $audit,
        private readonly TenantContext $context,
        private readonly TenantDatabaseManager $tenants,
    ) {}

    /**
     * A temporary signed URL for one document, optionally bound to a reader.
     *
     * The reader rides inside the signature with the central tenant id, so a
     * confidential download is attributable to the account that minted it
     * rather than to “whoever held the link”.
     */
    public function url(EmployeeDocument $document, ?User $reader = null): string
    {
        $parameters = ['document' => $document->id, 'tenant' => $this->tenantId()];

        if ($reader !== null) {
            $parameters['actor'] = $reader->id;
        }

        return url()->temporarySignedRoute('hrms.documents.download', now()->addHours(1), $parameters);
    }

    /**
     * Stream one file for a signed request, with no session and no tenant
     * context: the central tenant id and the reader arrive inside the
     * signature, and the record is resolved inside the tenant connection.
     */
    public function stream(int $documentId, int $tenantId, ?int $actorUserId, ?string $ipAddress): StreamedResponse
    {
        $tenant = Tenant::find($tenantId);
        abort_if($tenant === null, 404);

        return $this->tenants->using($tenant, function () use ($documentId, $actorUserId, $ipAddress): StreamedResponse {
            $document = EmployeeDocument::query()->find($documentId);
            abort_if($document === null, 404);

            $reader = $actorUserId === null ? null : User::find($actorUserId);
            $this->requireDownloadReader($document, $reader);

            if ($document->confidential) {
                $this->audit->accessed(
                    (new EmployeeDocument)->getMorphClass(),
                    $document->id,
                    DataAccessAction::Download,
                    ['file_path'],
                    $reader,
                    $ipAddress,
                );
            }

            if (! Storage::disk($document->file_disk)->exists($document->file_path)) {
                abort(404, 'File no longer exists.');
            }

            return Storage::disk($document->file_disk)->download($document->file_path, $document->original_name);
        });
    }

    private function tenantId(): int
    {
        $tenantId = $this->context->currentId();

        if ($tenantId === null) {
            throw ValidationException::withMessages(['form' => 'Documents need an active tenant before a link can be minted.']);
        }

        return $tenantId;
    }

    /**
     * The download permission, after the record is resolved: the reader named
     * in the URL must satisfy the exact same rule as the JSON show
     * (EmployeeDocumentPolicy::view — self or lifecycle-trusted, plus the
     * sensitive permission when confidential). Nobody anonymous: a forwarded
     * link is a bearer token, and the file it points at can name a condition,
     * an account or a person.
     */
    private function requireDownloadReader(EmployeeDocument $document, ?User $reader): void
    {
        if ($reader === null) {
            abort(403, 'This download needs a signed reader.');
        }

        $reader->loadMissing('roles.permissions');

        Gate::forUser($reader)->authorize('view', $document);
    }
}
