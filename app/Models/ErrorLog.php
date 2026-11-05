<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One rollup row per application error fingerprint (system DB).
 *
 * `occurrences` counts every captured event; the per-event rows live in
 * `error_occurrences`. Resolving or ignoring an entry never hides new events:
 * the row keeps counting them and `recurred()` reports whether anything landed
 * after `resolved_at`.
 */
class ErrorLog extends Model
{
    use CentralConnection;

    public const STATUS_OPEN = 'open';

    public const STATUS_INVESTIGATING = 'investigating';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_IGNORED = 'ignored';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_INVESTIGATING, self::STATUS_RESOLVED, self::STATUS_IGNORED];

    public const SEVERITIES = ['warning', 'error', 'critical', 'alert', 'emergency'];

    public const SOURCES = ['backend', 'queue', 'console', 'frontend'];

    protected $fillable = [
        'fingerprint', 'exception_class', 'message', 'severity', 'status', 'module', 'source',
        'environment', 'tenant_id', 'request_id', 'user_id', 'path', 'method', 'http_status',
        'file', 'line', 'stack_trace', 'context', 'occurrences', 'first_seen_at', 'last_seen_at',
        'resolved_at', 'resolved_by', 'ignored_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurrences' => 'integer',
            'http_status' => 'integer',
            'line' => 'integer',
            'tenant_id' => 'integer',
            'user_id' => 'integer',
            'resolved_by' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'ignored_at' => 'datetime',
        ];
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(ErrorOccurrence::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ErrorLogNote::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(ErrorLogStatusHistory::class);
    }

    /** True when at least one event landed after the last resolution. */
    public function recurred(): bool
    {
        return $this->resolved_at !== null
            && $this->last_seen_at !== null
            && $this->last_seen_at->greaterThan($this->resolved_at);
    }
}
