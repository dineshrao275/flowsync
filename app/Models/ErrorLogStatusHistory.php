<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only status transition history for an application error (system DB). */
class ErrorLogStatusHistory extends Model
{
    use CentralConnection;

    public const UPDATED_AT = null;

    protected $table = 'error_log_status_history';

    protected $fillable = ['error_log_id', 'from_status', 'to_status', 'actor_id', 'actor_name', 'note', 'created_at'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'actor_id' => 'integer',
        ];
    }

    public function errorLog(): BelongsTo
    {
        return $this->belongsTo(ErrorLog::class);
    }
}
