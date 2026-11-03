<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A personal API token (tenant DB). The plaintext is shown once at creation and never stored. */
class ApiToken extends Model
{
    protected $fillable = [
        'user_id', 'name', 'token_hash', 'token_hint', 'abilities', 'can_write', 'rate_limit',
        'expires_at', 'last_used_at', 'last_used_ip', 'revoked_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'can_write' => 'boolean',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(IntegrationLog::class, 'token_id');
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * Whether the token carries an ability (`*` = every supported ability).
     *
     * Legacy `workspaces.view` tokens predate the capability groups. They keep exactly what they always had —
     * read access (`tms.read`) — and nothing more: `tms.write` is never inferred from them, so a token minted
     * to create tasks does not silently gain edit/move/comment/log-time. Routes that a legacy token could
     * already reach name `workspaces.view` themselves (see `routes/web/platform_engines_v1.php`).
     */
    public function allows(string $ability): bool
    {
        $abilities = (array) $this->abilities;

        if (in_array('*', $abilities, true) || in_array($ability, $abilities, true)) {
            return true;
        }

        return $ability === 'tms.read' && in_array('workspaces.view', $abilities, true);
    }
}
