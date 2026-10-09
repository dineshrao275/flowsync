<?php

namespace App\Models\Hrms\Document;

use App\Enums\Hrms\DocumentSource;
use App\Enums\Hrms\DocumentStatus;
use App\Enums\Hrms\DocumentVisibility;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Document/HRMS — one stored file, with a lifecycle of its own.
 *
 * Soft, always: a deleted row is still evidence that a document existed, and
 * HR must be able to answer “what did we hold on this person, and when” after
 * the file is gone. The file itself is removed on delete; the row is not.
 *
 * `document_type_id` is nullable because the catalogue is a picker, not the
 * truth: a document outlives the type row it was filed under, and deleting a
 * category must orphan the row rather than destroy a statutory document.
 *
 * @property int $id
 * @property int $employee_id
 * @property int|null $document_type_id
 * @property string $title
 * @property string $file_disk
 * @property string $file_path
 * @property string $original_name
 * @property string|null $mime
 * @property int|null $size
 * @property Carbon|null $issued_at
 * @property Carbon|null $expires_at
 * @property DocumentStatus $status
 * @property Carbon|null $verified_at
 * @property int|null $verified_by_user_id
 * @property string|null $rejection_reason
 * @property DocumentVisibility $visibility
 * @property bool $confidential
 * @property DocumentSource $source
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Employee $employee
 * @property-read DocumentType|null $type
 * @property-read User|null $verifiedBy
 * @property-read User|null $createdBy
 */
class EmployeeDocument extends Model
{
    use SoftDeletes;

    protected $table = 'employee_documents';

    protected $fillable = [
        'employee_id',
        'document_type_id',
        'title',
        'file_disk',
        'file_path',
        'original_name',
        'mime',
        'size',
        'issued_at',
        'expires_at',
        'status',
        'verified_at',
        'verified_by_user_id',
        'rejection_reason',
        'visibility',
        'confidential',
        'source',
        'created_by',
        'version',
        'is_current',
        'supersedes_id',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'document_type_id' => 'integer',
            'size' => 'integer',
            'issued_at' => 'date',
            'expires_at' => 'date',
            'status' => DocumentStatus::class,
            'verified_at' => 'datetime',
            'verified_by_user_id' => 'integer',
            'visibility' => DocumentVisibility::class,
            'confidential' => 'boolean',
            'source' => DocumentSource::class,
            'created_by' => 'integer',
            'version' => 'integer',
            'is_current' => 'boolean',
            'supersedes_id' => 'integer',
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

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Only the newest version of each document: a replaced file has no
     * expiry worth warning about and does not belong in the store list.
     *
     * @param  Builder<EmployeeDocument>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->where('is_current', true);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /**
     * The rows expiry still applies to. A rejected document is already closed,
     * and an expired or rejected row has no future expiry to warn about — so
     * both the warning query and the expiry command read this scope rather than
     * “has an expires_at”.
     *
     * @param  Builder<EmployeeDocument>  $query
     */
    public function scopeAwaitingAction(Builder $query): void
    {
        $query->whereIn('status', [DocumentStatus::Pending->value, DocumentStatus::Verified->value]);
    }

    /**
     * Live documents whose expiry falls on or before the given date.
     *
     * @param  Builder<EmployeeDocument>  $query
     */
    public function scopeExpiringBy(Builder $query, Carbon $date): void
    {
        $query->current()
            ->awaitingAction()
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', $date->toDateString());
    }
}
