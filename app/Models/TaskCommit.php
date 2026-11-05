<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Git integration row (tenant TMS DB). See database/migrations/tenant_tms/*_create_task_vcs_tables.php. */
class TaskCommit extends Model
{
    protected $fillable = ['task_id', 'project_repository_id', 'sha', 'message', 'author_login', 'author_name', 'html_url', 'committed_at'];

    protected function casts(): array
    {
        return [
            'committed_at' => 'datetime',
        ];
    }
}
