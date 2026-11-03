<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Git integration row (tenant TMS DB). See database/migrations/tenant_tms/*_create_task_vcs_tables.php. */
class TaskBranch extends Model
{
    protected $fillable = ['task_id', 'project_repository_id', 'name', 'provider_ref', 'created_by', 'deleted_at'];

    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
        ];
    }
}
