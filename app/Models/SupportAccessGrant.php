<?php

namespace App\Models;

use App\Models\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A tenant admin's time-boxed consent for the platform team to enter the tenant (P8.4). */
class SupportAccessGrant extends Model
{
    use CentralConnection;

    protected $fillable = [
        'tenant_id', 'granted_by_user_id', 'granted_by_name', 'granted_by_email', 'mode', 'note',
        'support_ticket_id', 'expires_at', 'session_minutes', 'revoked_at', 'revoked_by_name', 'uses', 'last_used_at',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
