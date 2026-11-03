<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A Git provider installation (GitHub App) a tenant connected. Lives in the system DB:
 * the installation id routes a webhook to its tenant before any tenant DB is open.
 *
 * @property string $uuid
 * @property int $tenant_id
 * @property int $installation_id
 * @property string $status
 * @property Carbon|null $last_synced_at
 */
class GitConnection extends Model
{
    use CentralConnection;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'uuid', 'tenant_id', 'provider', 'installation_id', 'account_login', 'account_type', 'auth_type',
        'access_token', 'refresh_token', 'token_expires_at', 'scopes', 'settings', 'status',
        'suspended_at', 'revoked_at', 'connected_by', 'connected_by_name', 'last_synced_at',
    ];

    /** Credentials never leave the server, not even in a stray `toArray()`. */
    protected $hidden = ['access_token', 'refresh_token'];

    protected static function booted(): void
    {
        static::creating(function (self $connection): void {
            $connection->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'scopes' => 'array',
            'settings' => 'array',
            'token_expires_at' => 'datetime',
            'suspended_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function repositories(): HasMany
    {
        return $this->hasMany(GitRepository::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
