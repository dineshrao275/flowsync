<?php

declare(strict_types=1);

namespace App\Services\Ai\Proposals;

use App\Models\AiProposal;
use App\Models\Priority;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\CustomFields\CustomFieldService;
use App\Services\Tasks\TaskChangeLogger;
use App\Services\TaskService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Turns a reviewed proposal into real tasks, through the same service the task form uses.
 *
 * Safety properties, each tested:
 *  - nothing exists before this runs (proposals are drafts);
 *  - permission is re-checked NOW, not at preview time;
 *  - the proposal row is locked, and finished items are recorded, so a double click or a retry
 *    after a partial failure can never create the same task twice;
 *  - each task is its own savepoint, so one rejected item (a plan limit, a required custom field)
 *    is reported on that item and the rest still go through.
 */
final class ProposalConfirmer
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly TaskChangeLogger $changes,
        private readonly ActivityLogger $activity,
        private readonly CustomFieldService $customFields,
    ) {}

    /** @throws AuthorizationException */
    public function confirm(User $user, string $id, ?string $ip): AiProposal
    {
        $created = [];

        $proposal = DB::transaction(function () use ($user, $id, &$created): AiProposal {
            /** @var AiProposal $proposal */
            $proposal = AiProposal::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($id);

            if ($proposal->status === AiProposal::CONFIRMED) {
                return $proposal; // already done: answer with what happened, create nothing
            }
            if ($proposal->status === AiProposal::REJECTED) {
                throw ValidationException::withMessages(['form' => __('This proposal was rejected.')]);
            }

            $project = Project::find($proposal->project_id);
            if ($project === null || Gate::forUser($user)->denies('view', $project)) {
                throw ValidationException::withMessages(['form' => __('The project is no longer available to you.')]);
            }
            Gate::forUser($user)->authorize('createTask', $project);

            $results = (array) ($proposal->result['items'] ?? []);
            foreach ((array) $proposal->payload['items'] as $item) {
                $done = $results[$item['id']] ?? null;
                if (($done['status'] ?? null) === 'created' || ! $item['selected']) {
                    continue;
                }

                $results[$item['id']] = $this->createOne($user, $project, $item, $created);
            }

            $proposal->forceFill(['result' => ['items' => $results], 'status' => $this->status($proposal, $results), 'confirmed_at' => now()])->save();

            return $proposal;
        });

        $this->afterCommit($user, $proposal, $created, $ip);

        return $proposal;
    }

    /**
     * @param  array<string,mixed>  $item
     * @param  list<Task>  $created
     * @return array<string,mixed>
     */
    private function createOne(User $user, Project $project, array $item, array &$created): array
    {
        try {
            $task = DB::transaction(function () use ($user, $project, $item): Task {
                $custom = $this->customFields->enabled() ? $this->customFields->validate('task', [], false) : [];
                $task = $this->tasks->create($project, $this->data($item), $user);
                $this->customFields->save('task', $task->id, $custom);

                return $task;
            });
        } catch (ValidationException $e) {
            return ['status' => 'failed', 'error' => collect($e->errors())->flatten()->first() ?? __('Could not create this task.')];
        }

        $created[] = $task;

        return ['status' => 'created', 'task_id' => $task->id, 'key' => $task->key, 'project_id' => $task->project_id];
    }

    /**
     * Revalidated here, not trusted from storage: the stored item passed ProposalItems already, and the
     * priority is looked up again so a priority deleted since the preview falls back to the default.
     *
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function data(array $item): array
    {
        $data = ['title' => $item['title'], 'description' => $item['description'] !== '' ? nl2br(e($item['description'])) : null];

        if ($item['priority'] !== null && ($priority = Priority::where('slug', $item['priority'])->first())) {
            $data['priority_id'] = $priority->id;
        }
        if ($item['story_points'] !== null) {
            $data['story_points'] = $item['story_points'];
        }

        return $data;
    }

    /** @param array<string,array<string,mixed>> $results */
    private function status(AiProposal $proposal, array $results): string
    {
        $wanted = collect($proposal->payload['items'])->where('selected', true)->pluck('id');
        $ok = $wanted->filter(fn ($id) => ($results[$id]['status'] ?? null) === 'created')->count();

        return match (true) {
            $wanted->isEmpty() || $ok === $wanted->count() => AiProposal::CONFIRMED,
            $ok > 0 => AiProposal::PARTIAL,
            default => AiProposal::FAILED,
        };
    }

    /**
     * Activity rows and realtime events go out after the data is committed, so nobody is told about a
     * task a rollback then removes.
     *
     * @param  list<Task>  $created
     */
    private function afterCommit(User $user, AiProposal $proposal, array $created, ?string $ip): void
    {
        foreach ($created as $task) {
            $this->changes->created($task, $user, $ip, ['via' => 'ai', 'proposal_id' => $proposal->id]);
        }

        if ($created !== []) {
            $this->activity->log(Project::class, (int) $proposal->project_id, 'ai.tasks_created', [
                'proposal_id' => $proposal->id,
                'count' => count($created),
                'keys' => array_map(fn (Task $t) => $t->key, $created),
            ], $user, $ip);
        }
    }
}
