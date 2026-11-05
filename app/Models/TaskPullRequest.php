<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Git integration row (tenant TMS DB). See database/migrations/tenant_tms/*_create_task_vcs_tables.php. */
class TaskPullRequest extends Model
{
    protected $fillable = ['task_id', 'project_repository_id', 'provider', 'provider_pr_id', 'number', 'title', 'state', 'draft', 'author_login', 'author_avatar_url', 'html_url', 'head_branch', 'base_branch', 'merged_at', 'checks_state', 'last_event_at'];

    protected function casts(): array
    {
        return [
            'draft' => 'boolean',
            'merged_at' => 'datetime',
            'last_event_at' => 'datetime',
        ];
    }
}
