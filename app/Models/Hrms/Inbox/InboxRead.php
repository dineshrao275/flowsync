<?php

namespace App\Models\Hrms\Inbox;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Inbox/HRMS — one read receipt for one queue item.
 *
 * Per (login, item key): the queue itself is derived live, so only reads
 * persist. Deleting the login takes its receipts; an item key that stops
 * resolving (a decided approval, a returned asset) simply never matches
 * again, and its row is harmless history.
 */
class InboxRead extends Model
{
    protected $table = 'inbox_reads';

    protected $fillable = [
        'user_id',
        'item_key',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
