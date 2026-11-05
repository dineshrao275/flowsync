<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One captured event behind an {@see ErrorLog} rollup row (system DB). */
class ErrorOccurrence extends Model
{
    use CentralConnection;

    protected $fillable = [
        'error_log_id', 'occurred_at', 'request_id', 'tenant_id', 'user_id',
        'path', 'method', 'http_status', 'context',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'datetime',
            'http_status' => 'integer',
            'tenant_id' => 'integer',
            'user_id' => 'integer',
        ];
    }

    public function errorLog(): BelongsTo
    {
        return $this->belongsTo(ErrorLog::class);
    }
}
