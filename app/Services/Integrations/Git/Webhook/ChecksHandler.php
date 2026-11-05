<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git\Webhook;

use App\Models\GitConnection;
use App\Models\Task;
use App\Models\TaskPullRequest;

/**
 * `check_suite`, `check_run` and `status`: move the checks state of the open pull requests they belong to. Only a real
 * change of state is written or announced, so a chatty CI never floods the activity feed.
 */
class ChecksHandler implements GitEventHandler
{
    private const FAILED = ['failure', 'timed_out', 'cancelled', 'action_required', 'startup_failure', 'error'];

    public function __construct(private readonly GitLinker $linker) {}

    public function handle(GitConnection $connection, string $event, string $action, array $payload, string $delivery): string
    {
        [$state, $numbers, $branches] = match ($event) {
            'check_suite' => [$this->suiteState((array) ($payload['check_suite'] ?? [])), $this->numbers($payload['check_suite'] ?? []), [$payload['check_suite']['head_branch'] ?? null]],
            'check_run' => [$this->runState((array) ($payload['check_run'] ?? []), $action), $this->numbers($payload['check_run'] ?? []), [$payload['check_run']['check_suite']['head_branch'] ?? null]],
            default => [$this->statusState((string) ($payload['state'] ?? '')), [], array_column((array) ($payload['branches'] ?? []), 'name')],
        };
        $branches = array_values(array_filter($branches));
        if ($state === null || ($numbers === [] && $branches === [])) {
            return GitWebhookProcessor::IGNORED;
        }

        $changed = false;
        foreach ($this->linker->links($connection, $this->linker->repoId($payload)) as $link) {
            $rows = TaskPullRequest::query()->where('project_repository_id', $link->id)->whereIn('state', ['open', 'draft'])
                ->where(fn ($q) => $q->whereIn('number', $numbers ?: [0])->orWhereIn('head_branch', $branches ?: ['']))->get();

            foreach ($rows as $row) {
                if ($row->checks_state === $state) {
                    continue;
                }
                $row->forceFill(['checks_state' => $state, 'last_event_at' => now()])->save();
                $task = Task::query()->find($row->task_id);
                if ($task !== null && $this->linker->claim($delivery, $link, $task, 'checks', "{$row->number}:{$state}")) {
                    $this->linker->emit($task, $link, $delivery, [
                        'kind' => 'checks', 'state' => $state, 'title' => $row->title, 'url' => $row->html_url, 'number' => $row->number,
                    ]);
                }
                $changed = true;
            }
        }

        return $changed ? GitWebhookProcessor::PROCESSED : GitWebhookProcessor::IGNORED;
    }

    /** @param  array<string, mixed>  $source */
    private function numbers(mixed $source): array
    {
        return array_values(array_filter(array_map(fn ($p) => (int) ($p['number'] ?? 0), (array) ($source['pull_requests'] ?? []))));
    }

    /** @param  array<string, mixed>  $suite */
    private function suiteState(array $suite): ?string
    {
        if (($suite['status'] ?? '') !== 'completed') {
            return in_array($suite['status'] ?? '', ['queued', 'in_progress', 'requested'], true) ? 'pending' : null;
        }

        return $this->conclusion((string) ($suite['conclusion'] ?? ''));
    }

    /** A single run only moves the state to failure or pending; the suite is what declares success. */
    private function runState(array $run, string $action): ?string
    {
        if (($run['status'] ?? '') !== 'completed') {
            return in_array($action, ['created', 'rerequested'], true) || ($run['status'] ?? '') === 'in_progress' ? 'pending' : null;
        }
        $state = $this->conclusion((string) ($run['conclusion'] ?? ''));

        return $state === 'failure' ? 'failure' : null;
    }

    private function statusState(string $state): ?string
    {
        return match (true) {
            $state === 'success' => 'success',
            $state === 'pending' => 'pending',
            in_array($state, self::FAILED, true) => 'failure',
            default => null,
        };
    }

    private function conclusion(string $conclusion): ?string
    {
        return match (true) {
            $conclusion === 'success' => 'success',
            in_array($conclusion, self::FAILED, true) => 'failure',
            in_array($conclusion, ['neutral', 'skipped', 'stale'], true) => 'neutral',
            default => null,
        };
    }
}
