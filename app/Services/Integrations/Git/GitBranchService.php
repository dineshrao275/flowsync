<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git;

use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\Task;
use App\Models\TaskBranch;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\PlatformAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Creates a branch on the provider from a task. The caller has already been authorised (git.manage + project
 * settings); this enforces the link rules and turns provider refusals into messages a person can act on.
 */
class GitBranchService
{
    public function __construct(
        private readonly GitProvider $provider,
        private readonly GitRepositoryCatalog $catalog,
        private readonly ActivityLogger $activity,
        private readonly PlatformAudit $audit,
    ) {}

    public function create(Tenant $tenant, Project $project, ProjectRepository $link, Task $task, User $actor, ?Request $request = null): TaskBranch
    {
        if ($link->project_id !== $project->id || $task->project_id !== $project->id) {
            throw $this->fail(__('That task or repository does not belong to this project.'));
        }
        if (! $link->sync_enabled) {
            throw $this->fail(__('This repository is paused for the project. Turn it back on before creating branches.'));
        }

        try {
            $repository = $this->catalog->findForLink($tenant, $link);
        } catch (GitConnectionException) {
            throw $this->fail(__('This repository is not available.'));
        }
        if (! $repository->available || ! $repository->connection?->isActive()) {
            throw $this->fail(__('This repository is not available.'));
        }

        $name = BranchNamer::for($task);
        $base = $link->default_branch ?: ($repository->default_branch ?: 'main');
        if (TaskBranch::query()->where('project_repository_id', $link->id)->where('name', $name)->whereNull('deleted_at')->exists()) {
            throw $this->fail(__('A branch named :name already exists for this task.', ['name' => $name]));
        }

        try {
            $this->provider->createBranch((int) $repository->connection->installation_id, $repository->full_name, $name, $base);
        } catch (GitProviderException $e) {
            throw $this->translate($e, $name, $base);
        }

        $branch = TaskBranch::query()->updateOrCreate(
            ['project_repository_id' => $link->id, 'name' => $name],
            ['task_id' => $task->id, 'provider_ref' => 'refs/heads/'.$name, 'created_by' => $actor->id, 'deleted_at' => null],
        );
        Cache::forget("git:browse:{$repository->id}:branches");

        $this->activity->log(Task::class, $task->id, 'task.vcs_event', [
            'provider' => $link->provider, 'kind' => 'branch', 'state' => 'created', 'title' => $name, 'repo' => $link->full_name,
            'url' => rtrim((string) $link->html_url, '/').'/tree/'.rawurlencode($name), 'key' => $task->key,
        ], $actor, $request?->ip());
        $this->audit->record($request, 'git.branch_created', 'task', $task->id, [
            'tenant_id' => $tenant->id, 'repo' => $link->full_name, 'branch' => $name, 'from' => $base, 'actor_user_id' => $actor->id, 'actor_name' => $actor->name,
        ]);

        return $branch;
    }

    private function translate(GitProviderException $e, string $name, string $base): \Throwable
    {
        return match (true) {
            $e->status === 422 => $this->fail(__('A branch named :name already exists in the repository.', ['name' => $name])),
            $e->status === 409 => $this->fail(__('The repository is empty. Make a first commit before creating branches.')),
            $e->status === 404 => $this->fail(__('The base branch :base was not found, or FlowSync cannot access this repository.', ['base' => $base])),
            $e->status === 403 && ! $e->isRateLimited() => $this->fail(__('FlowSync is not allowed to create branches here. Ask the GitHub organization admin to grant the app "Contents: Read and write".')),
            default => $e,
        };
    }

    private function fail(string $message): ValidationException
    {
        return ValidationException::withMessages(['form' => $message]);
    }
}
