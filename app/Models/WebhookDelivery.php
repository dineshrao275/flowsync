<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    protected $fillable = ['endpoint_id', 'event_uuid', 'event_type', 'payload', 'status', 'attempts', 'response_status', 'response_excerpt', 'error', 'next_attempt_at', 'delivered_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'next_attempt_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'endpoint_id');
    }
}
