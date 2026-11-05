<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A triage note on an application error (system DB, staff-only). */
class ErrorLogNote extends Model
{
    use CentralConnection;

    protected $fillable = ['error_log_id', 'author_id', 'author_name', 'body'];

    protected function casts(): array
    {
        return [
            'author_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function errorLog(): BelongsTo
    {
        return $this->belongsTo(ErrorLog::class);
    }
}
