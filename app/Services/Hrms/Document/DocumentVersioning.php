<?php

namespace App\Services\Hrms\Document;

use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Document/HRMS — replacing a file without losing the one it replaces.
 *
 * A new version is a new row: it inherits the title, type, visibility,
 * confidentiality and source of the row it supersedes (so a replacement can
 * never quietly turn a confidential file public), starts `pending` again
 * because new bytes are new evidence, and takes over `is_current`. The old file
 * stays downloadable through its own row; only lists and expiry warnings stop
 * reading it. The bytes go through the same {@see DocumentUpload} rules (type,
 * allow-list, size), and a failed insert removes the stored file again.
 */
class DocumentVersioning
{
    private const MAX_CHAIN = 100;

    public function __construct(
        private readonly DocumentUpload $uploads,
        private readonly DocumentPresenter $presenter,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $meta  optional `issued_at` / `expires_at`
     *
     * @throws ValidationException when the row is not the current version or the file is refused
     */
    public function addVersion(EmployeeDocument $current, UploadedFile $file, array $meta = [], ?User $actor = null): EmployeeDocument
    {
        if (! $current->is_current || $current->trashed()) {
            throw ValidationException::withMessages(['form' => 'Only the current version of a document can be replaced.']);
        }

        $current->loadMissing(['employee', 'type']);

        $stored = $this->uploads->store($current->employee, $current->type, $file, [
            'title' => $current->title,
            'visibility' => $current->visibility->value,
            'confidential' => $current->confidential,
            'source' => $current->source->value,
            'issued_at' => $meta['issued_at'] ?? null,
            'expires_at' => $meta['expires_at'] ?? null,
        ], $actor);

        try {
            return DB::transaction(function () use ($current, $stored, $actor): EmployeeDocument {
                // Re-read under the transaction: two replacements racing on the
                // same row must not both become "the next version".
                $locked = EmployeeDocument::query()->whereKey($current->id)->lockForUpdate()->firstOrFail();
                if (! $locked->is_current) {
                    throw ValidationException::withMessages(['form' => 'This document was replaced by someone else. Reload and try again.']);
                }

                $next = EmployeeDocument::create([
                    ...$stored['attributes'],
                    'file_path' => $stored['path'],
                    'version' => $locked->version + 1,
                    'is_current' => true,
                    'supersedes_id' => $locked->id,
                ]);
                $locked->update(['is_current' => false]);

                $this->audit->log($next, 'document.version_added', ['version' => $locked->version], [
                    ...$this->presenter->snapshot($next),
                    'version' => $next->version,
                    'supersedes_id' => $locked->id,
                ], $actor);

                return $next->refresh();
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($stored['path']);

            throw $exception;
        }
    }

    /**
     * Every version of the document's lineage, oldest first.
     *
     * @return Collection<int, EmployeeDocument>
     */
    public function history(EmployeeDocument $document): Collection
    {
        $root = $document;
        for ($i = 0; $i < self::MAX_CHAIN && $root->supersedes_id !== null; $i++) {
            $parent = EmployeeDocument::withTrashed()->find($root->supersedes_id);
            if ($parent === null) {
                break;
            }
            $root = $parent;
        }

        $chain = collect([$root]);
        $cursor = $root;
        for ($i = 0; $i < self::MAX_CHAIN; $i++) {
            $next = EmployeeDocument::withTrashed()->where('supersedes_id', $cursor->id)->first();
            if ($next === null) {
                break;
            }
            $chain->push($next);
            $cursor = $next;
        }

        return $chain->filter(fn (EmployeeDocument $d): bool => ! $d->trashed())->values();
    }
}
