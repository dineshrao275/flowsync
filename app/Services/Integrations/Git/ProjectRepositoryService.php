<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git;

use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Links repositories to projects. The repository must belong to one of THIS tenant's active connections
 * (resolved through the catalog, never by a client-supplied provider id), so a project can only ever follow
 * a repository its own organisation was granted.
 */
class ProjectRepositoryService
{
    /** Task-side tables that hold data derived from a link (created in Phase 5). */
    private const LINKED_DATA_TABLES = ['task_pull_requests', 'task_commits', 'task_branches'];

    public function __construct(private readonly GitRepositoryCatalog $catalog, private readonly ActivityLogger $activity) {}

    /** @return list<ProjectRepository> */
    public function list(Project $project): array
    {
        return ProjectRepository::query()->where('project_id', $project->id)->orderBy('full_name')->get()->all();
    }

    /**
     * @param  array{repository_uuid: string, sync_enabled?: bool, smart_commits_enabled?: bool, allow_cross_project?: bool, default_branch?: ?string}  $data
     */
    public function link(Tenant $tenant, Project $project, array $data, User $actor): ProjectRepository
    {
        try {
            $repository = $this->catalog->find($tenant, $data['repository_uuid']);
        } catch (GitConnectionException) {
            throw ValidationException::withMessages(['repository_uuid' => __('That repository is not available to this organization.')]);
        }
        if (! $repository->available || $repository->disabled) {
            throw ValidationException::withMessages(['repository_uuid' => __('That repository is not available to this organization.')]);
        }

        $exists = ProjectRepository::query()->where('project_id', $project->id)
            ->where('provider', $repository->provider)->where('provider_repo_id', $repository->provider_repo_id)->exists();
        if ($exists) {
            throw ValidationException::withMessages(['repository_uuid' => __('This repository is already linked to the project.')]);
        }

        $link = ProjectRepository::create([
            'project_id' => $project->id,
            'git_connection_uuid' => $repository->connection->uuid,
            'provider' => $repository->provider,
            'provider_repo_id' => $repository->provider_repo_id,
            'full_name' => $repository->full_name,
            'default_branch' => $data['default_branch'] ?? $repository->default_branch,
            'html_url' => $repository->html_url,
            'sync_enabled' => $data['sync_enabled'] ?? true,
            'smart_commits_enabled' => $data['smart_commits_enabled'] ?? (bool) config('git.smart_commits'),
            'allow_cross_project' => $data['allow_cross_project'] ?? (bool) config('git.allow_cross_project'),
            'linked_by' => $actor->id,
            'linked_at' => now(),
        ]);

        $this->activity->log(Project::class, $project->id, 'project.repository_linked', ['repo' => $link->full_name]);

        return $link;
    }

    /** @param  array{sync_enabled?: bool, smart_commits_enabled?: bool, allow_cross_project?: bool, default_branch?: ?string}  $data */
    public function update(ProjectRepository $link, array $data): ProjectRepository
    {
        $link->update($data);

        return $link->refresh();
    }

    /** Removes the link; derived task data is deleted only when the caller asked for it explicitly. */
    public function unlink(Project $project, ProjectRepository $link, bool $removeLinkedData = false): void
    {
        DB::transaction(function () use ($project, $link, $removeLinkedData): void {
            if ($removeLinkedData) {
                $this->purgeLinkedData($link);
            }
            $link->delete();
            $this->activity->log(Project::class, $project->id, 'project.repository_unlinked', ['repo' => $link->full_name, 'data_removed' => $removeLinkedData]);
        });
    }

    /**
     * A disconnected or revoked connection stops syncing every link it feeds, but keeps the rows: the project
     * history survives and re-connecting the same account picks them up again. Call inside the tenant DB.
     */
    public function pauseForConnection(string $connectionUuid): int
    {
        if (! Schema::hasTable('project_repositories')) {
            return 0;
        }

        return ProjectRepository::query()->where('git_connection_uuid', $connectionUuid)->where('sync_enabled', true)->update(['sync_enabled' => false]);
    }

    private function purgeLinkedData(ProjectRepository $link): void
    {
        foreach (self::LINKED_DATA_TABLES as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->where('project_repository_id', $link->id)->delete();
            }
        }
    }
}
