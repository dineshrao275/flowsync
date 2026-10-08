<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 5 — tenant-local export run tracker.
 *
 * One row per queued full-data export. The job writes the ZIP to local disk,
 * stamps `file_path`, `file_size`, and `expires_at`, then sets status=ready.
 * ExportController::download() validates the signature + expiry before streaming.
 *
 * @property int $id
 * @property int|null $user_id tenant-local user who requested it
 * @property array $categories requested export categories
 * @property string $status pending|processing|ready|failed
 * @property string|null $file_path storage-relative path to the ZIP
 * @property int|null $file_size bytes
 * @property string|null $error_message
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ExportRun extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'categories',
        'status',
        'file_path',
        'file_size',
        'error_message',
        'expires_at',
    ];

    protected $casts = [
        'categories' => 'array',
        'expires_at' => 'datetime',
        'file_size' => 'integer',
    ];

    /** Relationship to the tenant user who requested the export. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
