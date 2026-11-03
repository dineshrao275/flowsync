<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Model;

/** One inbound provider delivery (replay guard + platform health log). Payload bodies are never stored. */
class GitWebhookDelivery extends Model
{
    use CentralConnection;

    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_IGNORED = 'ignored';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'delivery_uuid', 'git_connection_id', 'tenant_id', 'event', 'action', 'status', 'error',
        'payload_bytes', 'payload_excerpt', 'received_at', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
