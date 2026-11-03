<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git;

use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\Task;
use App\Models\TaskBranch;
use App\Models\TaskCommit;
use App\Models\TaskPullRequest;
use App\Models\User;
use App\Support\TaskScope;
use Illuminate\Support\Collection;

/** Shapes the Development section of a task and the project-wide summary the board and overview read. */
class GitTaskPresenter
{
    /** Newest commits shown per task. */
    public const COMMIT_LIMIT = 20;

    /** Worst-first order used to roll several PRs up into one board badge. */
    private const CHECKS_SEVERITY = ['failure' => 4, 'pending' => 3, 'success' => 2, 'neutral' => 1, 'none' => 0];

    /** @return array<string, mixed> */
    public function forTask(Task $task, Project $project, User $user): array
    {
        $links = ProjectRepository::query()->where('project_id', $project->id)->orderBy('full_name')->get();
        $names = $links->pluck('full_name', 'id');

        return [
            'has_repository' => $links->isNotEmpty(),
            'can_create_branch' => $this->canCreateBranch($links, $project, $user),
            'repositories' => $links->map(fn (ProjectRepository $l) => [
                'id' => $l->id, 'full_name' => $l->full_name, 'html_url' => $l->html_url, 'sync_enabled' => $l->sync_enabled,
            ])->values(),
            'pull_requests' => TaskPullRequest::query()->where('task_id', $task->id)->orderByDesc('last_event_at')->orderByDesc('id')->get()
                ->map(fn (TaskPullRequest $p) => [
                    'number' => $p->number, 'title' => $p->title, 'state' => $p->state, 'draft' => $p->draft, 'checks_state' => $p->checks_state,
                    'author_login' => $p->author_login, 'author_avatar_url' => $p->author_avatar_url, 'html_url' => $p->html_url,
                    'head_branch' => $p->head_branch, 'base_branch' => $p->base_branch, 'merged_at' => $p->merged_at?->toIso8601String(),
                    'repository' => $names[$p->project_repository_id] ?? null,
                ])->values(),
            'commits' => TaskCommit::query()->where('task_id', $task->id)->orderByDesc('committed_at')->orderByDesc('id')->limit(self::COMMIT_LIMIT)->get()
                ->map(fn (TaskCommit $c) => [
                    'sha' => substr($c->sha, 0, 12), 'message' => $c->message, 'author_login' => $c->author_login, 'author_name' => $c->author_name,
                    'html_url' => $c->html_url, 'committed_at' => $c->committed_at?->toIso8601String(), 'repository' => $names[$c->project_repository_id] ?? null,
                ])->values(),
            'branches' => TaskBranch::query()->where('task_id', $task->id)->orderByDesc('id')->get()
                ->map(fn (TaskBranch $b) => [
                    'name' => $b->name, 'deleted' => $b->deleted_at !== null, 'repository' => $names[$b->project_repository_id] ?? null,
                    'html_url' => ($url = $links->firstWhere('id', $b->project_repository_id)?->html_url) ? rtrim($url, '/').'/tree/'.rawurlencode($b->name) : null,
                ])->values(),
        ];
    }

    /**
     * Per-task badge data for a project plus its linked repositories with the latest PR and commit. Rows are limited
     * to the tasks the caller may read, so a narrow task scope never leaks another person's PR counts.
     *
     * @return array{tasks: array<int, array{pull_requests: int, open: int, checks_state: string}>, repositories: list<array<string, mixed>>}
     */
    public function summary(Project $project, User $user): array
    {
        TaskScope::assertCanRead($project, $user);
        $visible = TaskScope::constrainQuery(
            Task::query()->where('tasks.project_id', $project->id),
            $user,
            TaskScope::resolveQueryScope($project, $user, 'tasks.view'),
        )->select('tasks.id');

        $tasks = TaskPullRequest::query()->whereIn('task_id', $visible)->get(['task_id', 'state', 'checks_state'])
            ->groupBy('task_id')->map(fn (Collection $rows) => [
                'pull_requests' => $rows->count(),
                'open' => $rows->whereIn('state', ['open', 'draft'])->count(),
                'checks_state' => $rows->whereIn('state', ['open', 'draft'])->pluck('checks_state')
                    ->sortByDesc(fn (string $s) => self::CHECKS_SEVERITY[$s] ?? 0)->first() ?? 'none',
            ])->all();

        $links = ProjectRepository::query()->where('project_id', $project->id)->orderBy('full_name')->get();

        return [
            'tasks' => $tasks,
            'repositories' => $links->map(function (ProjectRepository $l) use ($visible) {
                $pr = TaskPullRequest::query()->where('project_repository_id', $l->id)->whereIn('task_id', $visible)->orderByDesc('last_event_at')->orderByDesc('id')->first();
                $commit = TaskCommit::query()->where('project_repository_id', $l->id)->whereIn('task_id', $visible)->orderByDesc('committed_at')->orderByDesc('id')->first();

                return [
                    'id' => $l->id, 'full_name' => $l->full_name, 'html_url' => $l->html_url, 'sync_enabled' => $l->sync_enabled,
                    'last_pull_request' => $pr ? ['number' => $pr->number, 'title' => $pr->title, 'state' => $pr->state, 'html_url' => $pr->html_url] : null,
                    'last_commit' => $commit ? ['sha' => substr($commit->sha, 0, 12), 'message' => $commit->message, 'html_url' => $commit->html_url] : null,
                ];
            })->values()->all(),
        ];
    }

    /** @param  Collection<int, ProjectRepository>  $links */
    private function canCreateBranch(Collection $links, Project $project, User $user): bool
    {
        return $user->hasPermission('git.manage')
            && $user->can('settings', $project)
            && $links->contains(fn (ProjectRepository $l) => $l->sync_enabled);
    }
}
