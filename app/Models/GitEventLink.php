<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Git integration row (tenant TMS DB). See database/migrations/tenant_tms/*_create_task_vcs_tables.php. */
class GitEventLink extends Model
{
    protected $fillable = ['delivery_uuid', 'project_repository_id', 'task_id', 'kind', 'provider_object_id'];
}
