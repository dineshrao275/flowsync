<?php

namespace App\Models\Hrms\Lifecycle;

use App\Enums\Hrms\DocumentRequestSource;
use App\Enums\Hrms\DocumentRequestStatus;
use App\Models\Hrms\Document\DocumentType;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Lifecycle/HRMS — “we need this file from this person”.
 *
 * The hinge between the checklist and the document store: a case task with a
 * document category materialises one of these, and the row tracks the ask
 * from `pending` through `submitted` to `accepted`. `document_id` is the
 * fulfillment link — null until a file is submitted, nulled again if that
 * file is deleted, in which case the request visibly says “asked, file gone”
 * rather than silently un-asking.
 *
 * `case_type`/`case_id` is a plain pair, not a morph: the two case tables are
 * fixed, and a morph string is a typo away from pointing nowhere.
 *
 * @property int $id
 * @property int $employee_id
 * @property int|null $document_type_id
 * @property int|null $document_id
 * @property string $title
 * @property Carbon|null $due_date
 * @property DocumentRequestStatus $status
 * @property DocumentRequestSource $source
 * @property string|null $case_type
 * @property int|null $case_id
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Employee $employee
 * @property-read DocumentType|null $type
 * @property-read EmployeeDocument|null $document
 */
class DocumentRequest extends Model
{
    protected $table = 'document_requests';

    protected $fillable = [
        'employee_id',
        'document_type_id',
        'document_id',
        'title',
        'due_date',
        'status',
        'source',
        'case_type',
        'case_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'document_type_id' => 'integer',
            'document_id' => 'integer',
            'due_date' => 'date',
            'status' => DocumentRequestStatus::class,
            'source' => DocumentRequestSource::class,
            'case_id' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'document_id');
    }

    /**
     * Still outstanding: asked but not accepted, and not waived away. A
     * submitted row counts — the file is in, but nobody has said it is the
     * right file yet, and the clearance must not treat hope as evidence.
     *
     * @param  Builder<DocumentRequest>  $query
     */
    public function scopeOutstanding(Builder $query): void
    {
        $query->whereIn('status', [
            DocumentRequestStatus::Pending->value,
            DocumentRequestStatus::Submitted->value,
            DocumentRequestStatus::Rejected->value,
        ]);
    }

    /**
     * A submitted row whose file has since vanished: the link nulled, the
     * status still saying submitted. Progress must treat it as pending, or a
     * deleted file quietly completes a checklist.
     */
    public function isFulfilled(): bool
    {
        return $this->document_id !== null
            && in_array($this->status, [DocumentRequestStatus::Submitted, DocumentRequestStatus::Accepted], true);
    }
}
