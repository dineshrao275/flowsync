<?php

namespace App\Services\Hrms;

use App\Enums\Hrms\DataAccessAction;
use App\Enums\Hrms\DocumentCategory;
use App\Models\Hrms\Document\DocumentType;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use App\Services\Hrms\Document\DocumentDirectoryQuery;
use App\Services\Hrms\Document\DocumentDownload;
use App\Services\Hrms\Document\DocumentLifecycle;
use App\Services\Hrms\Document\DocumentPresenter;
use App\Services\Hrms\Document\DocumentUpload;
use App\Services\HrmsAuditLogger;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Document/HRMS — the employee-document use cases.
 *
 * A thin orchestrator on purpose. The rules live in the `Document/` bounded
 * context next to it: {@see DocumentUpload} owns what may be stored,
 * {@see DocumentLifecycle} owns what a status may become,
 * {@see DocumentDownload} owns the session-free file serving, and
 * {@see DocumentPresenter} owns the response shape. A controller, a command
 * or a future queue worker therefore cannot reimplement one rule and drift
 * from the others.
 */
class DocumentService
{
    public function __construct(
        private readonly DocumentUpload $uploads,
        private readonly DocumentLifecycle $lifecycle,
        private readonly DocumentDownload $downloads,
        private readonly DocumentPresenter $presenter,
        private readonly DocumentDirectoryQuery $directory,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /**
     * The upload picker’s catalogue: active types, ordered for display.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, DocumentType>
     */
    public function types(array $filters = []): Collection
    {
        $query = DocumentType::query()->orderBy('position')->orderBy('name');

        if (! empty($filters['q'])) {
            $term = '%'.mb_strtolower((string) $filters['q']).'%';
            $query->where(function ($nested) use ($term): void {
                $nested->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(slug) LIKE ?', [$term]);
            });
        }

        if (! empty($filters['category'])) {
            $query->where('category', DocumentCategory::from((string) $filters['category'])->value);
        }

        if (array_key_exists('active', $filters)) {
            $query->where('is_active', (bool) $filters['active']);
        } else {
            $query->active();
        }

        return $query->get();
    }

    /**
     * One active catalogue row. Retired types 404 rather than resurrect:
     * uploads may only file under a current type.
     */
    public function type(int $id): DocumentType
    {
        return DocumentType::active()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listFor(User $viewer, array $filters = []): LengthAwarePaginator
    {
        return $this->directory->paginateFor($viewer, $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function mine(User $viewer, array $filters = []): LengthAwarePaginator
    {
        return $this->directory->paginateMine($viewer, $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function expiringFor(User $viewer, int $days, array $filters = []): LengthAwarePaginator
    {
        return $this->directory->paginateExpiring($viewer, $days, $filters);
    }

    /**
     * One row for its reader, logging a confidential view.
     *
     * The signed download logs the file itself; this logs the metadata read,
     * because the title and original name can name the condition or the
     * account. Non-confidential rows log nothing — a ledger of ordinary reads
     * is noise that buries the rows that matter.
     */
    public function show(User $reader, EmployeeDocument $document, ?string $ipAddress): array
    {
        if ($document->confidential) {
            $this->audit->accessed(
                (new EmployeeDocument)->getMorphClass(),
                $document->id,
                DataAccessAction::View,
                ['title', 'original_name'],
                $reader,
                $ipAddress,
            );
        }

        return $this->present($document, $reader);
    }

    /**
     * Store a file and its row together.
     *
     * @param  array<string, mixed>  $meta
     */
    public function upload(
        Employee $employee,
        DocumentType $type,
        UploadedFile $file,
        array $meta = [],
        ?User $actor = null,
    ): EmployeeDocument {
        $stored = $this->uploads->store($employee, $type, $file, $meta, $actor);

        try {
            return DB::transaction(function () use ($stored, $actor): EmployeeDocument {
                $document = EmployeeDocument::create([...$stored['attributes'], 'file_path' => $stored['path']]);
                $this->audit->log($document, 'document.uploaded', null, $this->presenter->snapshot($document), $actor);

                return $document->refresh();
            });
        } catch (Throwable $exception) {
            // Storage is outside the database transaction, so a failed row
            // must clean up after itself or the disk keeps bytes no record
            // points at — retained data with no retention story.
            Storage::disk('local')->delete($stored['path']);

            throw $exception;
        }
    }

    public function verify(EmployeeDocument $document, ?User $actor = null): EmployeeDocument
    {
        return $this->lifecycle->verify($document, $actor);
    }

    public function reject(EmployeeDocument $document, string $reason, ?User $actor = null): EmployeeDocument
    {
        return $this->lifecycle->reject($document, $reason, $actor);
    }

    public function markExpired(EmployeeDocument $document, ?User $actor = null): EmployeeDocument
    {
        return $this->lifecycle->markExpired($document, $actor);
    }

    /**
     * @return Collection<int, EmployeeDocument>
     */
    public function expireDue(?Carbon $asOf = null): Collection
    {
        return $this->lifecycle->expireDue($asOf);
    }

    /**
     * @return Collection<int, EmployeeDocument>
     */
    public function expireSoon(Employee $employee, int $days): Collection
    {
        return $this->lifecycle->expireSoon($employee, $days);
    }

    public function delete(EmployeeDocument $document, ?User $actor = null): void
    {
        $this->lifecycle->delete($document, $actor);
    }

    public function present(EmployeeDocument $document, ?User $reader = null): array
    {
        return $this->presenter->present($document, $reader);
    }

    public function download(int $documentId, int $tenantId, ?int $actorUserId, ?string $ipAddress): StreamedResponse
    {
        return $this->downloads->stream($documentId, $tenantId, $actorUserId, $ipAddress);
    }
}
