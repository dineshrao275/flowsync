<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Git integration row (tenant TMS DB). See database/migrations/tenant_tms/*_create_task_vcs_tables.php. */
class GitIdentity extends Model
{
    protected $fillable = ['user_id', 'provider', 'provider_user_id', 'login', 'avatar_url', 'linked_at'];

    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
        ];
    }
}
