<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class WebhookEndpoint extends Model
{
    protected $fillable = ['url', 'description', 'secret', 'events', 'is_active', 'consecutive_failures', 'disabled_at', 'disabled_reason', 'created_by'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'is_active' => 'boolean',
            'disabled_at' => 'datetime',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'endpoint_id');
    }

    public static function newSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }

    /** Whether this endpoint subscribed to an event type (`*`, exact type, or `task.*` style). */
    public function wants(string $type): bool
    {
        return $this->is_active && Str::is($this->events ?: ['*'], $type);
    }
}
