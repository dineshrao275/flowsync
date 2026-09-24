<?php

namespace App\Models;

use App\Models\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Login routing index (Phase 12): mirrors each tenant DB's `users` in the
 * central/system database so a login resolves which tenant database a given
 * email belongs to, without scanning every tenant DB.
 */
class TenantUserRouting extends Model
{
    use CentralConnection;

    protected $table = 'tenant_users';

    protected $fillable = [
        'tenant_id',
        'email',
        'user_id',
        'name',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
