<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git\Webhook;

use App\Models\GitConnection;
use App\Models\TaskPullRequest;
use Illuminate\Support\Str;

/** `pull_request` and `pull_request_review`: keep each linked task's PR row current and say so once per change. */
class PullRequestHandler implements GitEventHandler
{
    public function __construct(private readonly GitLinker $linker) {}

    public function handle(GitConnection $connection, string $event, string $action, array $payload, string $delivery): string
    {
        $pr = $payload['pull_request'] ?? null;
        if (! is_array($pr) || $this->linker->isBot($payload)) {
            return GitWebhookProcessor::IGNORED;
        }

        $keys = $this->linker->keysIn($pr['title'] ?? null, Str::limit((string) ($pr['body'] ?? ''), GitLinker::BODY_CAP, ''), $pr['head']['ref'] ?? null);
        $links = $this->linker->links($connection, $this->linker->repoId($payload));
        $changed = false;

        foreach ($links as $link) {
            foreach ($this->linker->tasks($link, $keys) as $task) {
                $changed = ($event === 'pull_request_review'
                    ? $this->review($link, $task, $payload, $pr, $delivery)
                    : $this->upsert($link, $task, $pr, $delivery)) || $changed;
            }
        }

        return $changed ? GitWebhookProcessor::PROCESSED : GitWebhookProcessor::IGNORED;
    }

    /** @param  array<string, mixed>  $pr */
    private function upsert($link, $task, array $pr, string $delivery): bool
    {
        $state = ! empty($pr['merged']) || ! empty($pr['merged_at']) ? 'merged' : (($pr['state'] ?? 'open') === 'closed' ? 'closed' : (! empty($pr['draft']) ? 'draft' : 'open'));
        $row = TaskPullRequest::query()->firstOrNew(['task_id' => $task->id, 'project_repository_id' => $link->id, 'number' => (int) $pr['number']]);
        $previous = $row->exists ? $row->state : null;

        $row->fill([
            'provider' => $link->provider,
            'provider_pr_id' => $pr['id'] ?? null,
            'title' => Str::limit((string) ($pr['title'] ?? ''), 255, ''),
            'state' => $state,
            'draft' => (bool) ($pr['draft'] ?? false),
            'author_login' => $pr['user']['login'] ?? null,
            'author_avatar_url' => $this->linker->safeUrl($pr['user']['avatar_url'] ?? null),
            'html_url' => $this->linker->safeUrl($pr['html_url'] ?? null),
            'head_branch' => $pr['head']['ref'] ?? null,
            'base_branch' => $pr['base']['ref'] ?? null,
            'merged_at' => $pr['merged_at'] ?? null,
            'last_event_at' => now(),
        ])->save();

        // An edit or a new push to an already-known PR updates the row quietly; only a new PR or a state change is news.
        if ($previous === $state || ! $this->linker->claim($delivery, $link, $task, 'pull_request', "{$pr['number']}:{$state}")) {
            return false;
        }
        $this->linker->emit($task, $link, $delivery, [
            'kind' => 'pull_request', 'state' => $state, 'title' => $row->title, 'url' => $row->html_url, 'number' => $row->number,
        ]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $pr
     */
    private function review($link, $task, array $payload, array $pr, string $delivery): bool
    {
        $review = (array) ($payload['review'] ?? []);
        $state = ($payload['action'] ?? '') === 'dismissed' ? 'dismissed' : strtolower((string) ($review['state'] ?? 'commented'));
        if (empty($review['id']) || ! $this->linker->claimOnce($link, $task, 'review', $review['id'].':'.$state)) {
            return false;
        }
        $this->linker->emit($task, $link, $delivery, [
            'kind' => 'review', 'state' => $state, 'title' => Str::limit((string) ($pr['title'] ?? ''), 255, ''),
            'url' => $this->linker->safeUrl($review['html_url'] ?? $pr['html_url'] ?? null), 'number' => (int) ($pr['number'] ?? 0),
        ]);

        return true;
    }
}
