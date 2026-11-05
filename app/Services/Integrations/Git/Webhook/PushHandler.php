<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git\Webhook;

use App\Models\GitConnection;
use App\Models\TaskCommit;
use App\Services\Integrations\Git\Smart\GitSmartCommitService;
use Illuminate\Support\Str;

/** `push`: record the (capped) commits that mention a task key, once each. Branches are the create/delete handler's. */
class PushHandler implements GitEventHandler
{
    public function __construct(private readonly GitLinker $linker, private readonly GitSmartCommitService $smart) {}

    public function handle(GitConnection $connection, string $event, string $action, array $payload, string $delivery): string
    {
        $ref = (string) ($payload['ref'] ?? '');
        if (! str_starts_with($ref, 'refs/heads/') || ! empty($payload['deleted']) || $this->linker->isBot($payload)) {
            return GitWebhookProcessor::IGNORED;
        }

        $branch = substr($ref, 11);
        $links = $this->linker->links($connection, $this->linker->repoId($payload));
        $commits = array_slice((array) ($payload['commits'] ?? []), 0, (int) config('git.max_commits_per_push'));
        $changed = false;

        foreach ($commits as $commit) {
            $login = $commit['author']['username'] ?? $commit['committer']['username'] ?? null;
            if (! is_array($commit) || empty($commit['id']) || ($login !== null && str_ends_with(strtolower((string) $login), '[bot]'))) {
                continue;
            }
            $first = (string) strtok((string) ($commit['message'] ?? ''), "\n");
            // A commit on feature/ABC-12-x belongs to ABC-12 even when its message does not repeat the key.
            $keys = $this->linker->keysIn($first, $branch);

            foreach ($links as $link) {
                foreach ($this->linker->tasks($link, $keys) as $task) {
                    $row = TaskCommit::query()->firstOrCreate(
                        ['task_id' => $task->id, 'project_repository_id' => $link->id, 'sha' => (string) $commit['id']],
                        [
                            'message' => Str::limit($first, 500, ''),
                            'author_login' => $login,
                            'author_name' => isset($commit['author']['name']) ? Str::limit((string) $commit['author']['name'], 150, '') : null,
                            'html_url' => $this->linker->safeUrl($commit['url'] ?? null),
                            'committed_at' => $commit['timestamp'] ?? null,
                        ],
                    );
                    if ($row->wasRecentlyCreated && $this->linker->claim($delivery, $link, $task, 'commit', (string) $commit['id'])) {
                        $this->linker->emit($task, $link, $delivery, [
                            'kind' => 'commit', 'state' => 'pushed', 'title' => Str::limit($first, 140, ''), 'url' => $row->html_url, 'sha' => substr((string) $commit['id'], 0, 40),
                        ]);
                        $changed = true;
                    }
                    // Only the first time a commit is seen for a task: a merge that re-delivers it must not re-run it.
                    if ($row->wasRecentlyCreated) {
                        $this->smart->apply($link, $task, [
                            'sha' => (string) $commit['id'], 'message' => (string) ($commit['message'] ?? ''), 'login' => $login, 'timestamp' => $commit['timestamp'] ?? null,
                        ], $delivery);
                    }
                }
            }
        }

        return $changed ? GitWebhookProcessor::PROCESSED : GitWebhookProcessor::IGNORED;
    }
}
