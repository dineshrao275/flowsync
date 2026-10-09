<?php

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

    /** Whether the token carries an ability (`*` = every supported ability). */
    public function allows(string $ability): bool
    {
        $abilities = (array) $this->abilities;

        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }
}
