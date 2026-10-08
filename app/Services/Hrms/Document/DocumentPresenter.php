<?php

namespace App\Services\Hrms\Document;

use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\User;

/**
 * Document/HRMS — one document, shaped for a client.
 *
 * Separate from the service so “what a document looks like” cannot drift
 * between the verify response, the expiry command output and the profile tab:
 * every surface reads the same shape from here.
 */
class DocumentPresenter
{
    public function __construct(private readonly DocumentDownload $downloads) {}

    /**
     * One document, with its signed download.
     */
    public function present(EmployeeDocument $document, ?User $reader = null): array
    {
        $document->loadMissing(['type', 'employee']);

        return [
            'id' => $document->id,
            'employee_id' => $document->employee_id,
            'document_type_id' => $document->document_type_id,
            'title' => $document->title,
            'status' => $document->status->value,
            'visibility' => $document->visibility->value,
            'confidential' => $document->confidential,
            'source' => $document->source->value,
            'issued_at' => $document->issued_at?->toDateString(),
            'expires_at' => $document->expires_at?->toDateString(),
            'original_name' => $document->original_name,
            'mime' => $document->mime,
            'size' => $document->size,
            'type' => $document->type ? [
                'id' => $document->type->id,
                'name' => $document->type->name,
                'slug' => $document->type->slug,
                'category' => $document->type->category->value,
                'is_sensitive' => $document->type->is_sensitive,
            ] : null,
            // The store table names the file’s owner without a second
            // request per row; the relation is already loaded above.
            'employee' => $document->employee ? [
                'id' => $document->employee->id,
                'name' => $document->employee->displayName(),
            ] : null,
            'download_url' => $this->downloads->url($document, $reader),
        ];
    }

    /**
     * The non-identifying facts a ledger row needs: enough to answer what
     * changed and what it was, without the file itself.
     *
     * The title is deliberately absent. A title can name the condition, the
     * account or the person (“HIV result”, “salary slip”, “divorce decree”),
     * so writing it into an audit row would turn the ledger into a second
     * copy of the sensitive data. The row id plus the type id identify the
     * document; the title stays on the row.
     *
     * @return array<string, mixed>
     */
    public function snapshot(EmployeeDocument $document): array
    {
        return [
            'employee_id' => $document->employee_id,
            'document_type_id' => $document->document_type_id,
            'status' => $document->status->value,
        ];
    }
}
