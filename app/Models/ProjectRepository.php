<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property string $git_connection_uuid
 * @property int $provider_repo_id
 */
class ProjectRepository extends Model
{
    protected $fillable = [
        'project_id', 'git_connection_uuid', 'provider', 'provider_repo_id', 'full_name', 'default_branch', 'html_url',
        'sync_enabled', 'smart_commits_enabled', 'smart_commit_transitions_enabled', 'allow_cross_project', 'linked_by', 'linked_at',
    ];

    protected function casts(): array
    {
        return [
            'sync_enabled' => 'boolean',
            'smart_commits_enabled' => 'boolean',
            'smart_commit_transitions_enabled' => 'boolean',
            'allow_cross_project' => 'boolean',
            'linked_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
