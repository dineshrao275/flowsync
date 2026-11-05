<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git\Webhook;

use App\Events\TaskSynced;
use App\Models\GitConnection;
use App\Models\GitEventLink;
use App\Models\ProjectRepository;
use App\Models\Task;
use App\Services\ActivityLogger;
use App\Support\Realtime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The shared half of every event handler: which projects follow a repository, which task keys a piece of text
 * mentions, which of those tasks the link may touch, and how a change becomes exactly one activity line.
 */
class GitLinker
{
    /** Task keys look like ABC-123 (project key + number). Case-insensitive in text, normalised to upper case. */
    private const KEY = '/\b([A-Za-z][A-Za-z0-9]{1,9}-\d{1,6})\b/';

    /** PR/release bodies are scanned up to this many bytes. */
    public const BODY_CAP = 20_000;

    public function __construct(private readonly ActivityLogger $activity) {}

    /** @return Collection<int, ProjectRepository> the projects that follow this repository and have sync on */
    public function links(GitConnection $connection, int $repoId): Collection
    {
        if (! Schema::hasTable('project_repositories')) {
            return new Collection;
        }

        return ProjectRepository::query()->where('git_connection_uuid', $connection->uuid)
            ->where('provider_repo_id', $repoId)->where('sync_enabled', true)->get();
    }

    /** @return list<string> unique upper-case keys, capped so a pathological body cannot fan out */
    public function keysIn(?string ...$texts): array
    {
        $keys = [];
        foreach ($texts as $text) {
            if ($text !== null && preg_match_all(self::KEY, $text, $m)) {
                array_push($keys, ...array_map('strtoupper', $m[1]));
            }
        }

        return array_slice(array_values(array_unique($keys)), 0, (int) config('git.max_keys_per_event'));
    }

    /**
     * Tasks the keys name, restricted to the link's own project unless the link opted into cross-project matching.
     * Unknown keys simply do not match; nothing is created.
     *
     * @param  list<string>  $keys
     * @return Collection<int, Task>
     */
    public function tasks(ProjectRepository $link, array $keys): Collection
    {
        if ($keys === []) {
            return new Collection;
        }

        return Task::query()->whereIn('key', $keys)
            ->when(! $link->allow_cross_project, fn ($q) => $q->where('project_id', $link->project_id))->get();
    }

    /**
     * Claims (delivery, task, kind, object) in the ledger. False means this exact delivery already produced the
     * activity, so the caller must not write it again.
     */
    public function claim(string $delivery, ProjectRepository $link, Task $task, string $kind, string $objectId): bool
    {
        return GitEventLink::query()->firstOrCreate([
            'delivery_uuid' => $delivery, 'task_id' => $task->id, 'kind' => $kind, 'provider_object_id' => Str::limit($objectId, 100, ''),
        ], ['project_repository_id' => $link->id])->wasRecentlyCreated;
    }

    /**
     * Like claim(), but for things the provider names stably (a release id, a review id + state): the same object
     * delivered again under a NEW delivery id is still not news.
     */
    public function claimOnce(ProjectRepository $link, Task $task, string $kind, string $objectId): bool
    {
        return $this->claim("once:{$kind}:{$task->id}", $link, $task, $kind, $objectId);
    }

    /**
     * One `task.vcs_event` activity line + a realtime nudge. `$data` is the documented contract:
     * kind, state, title, url, repo (+ number/sha); provider and delivery are added here.
     *
     * @param  array<string, mixed>  $data
     */
    public function emit(Task $task, ProjectRepository $link, string $delivery, array $data): void
    {
        $this->activity->log(Task::class, $task->id, 'task.vcs_event', $data + [
            'provider' => $link->provider,
            'repo' => $link->full_name,
            'delivery' => $delivery,
            'key' => $task->key,
        ]);
        Realtime::send(new TaskSynced($task, 'vcs'));
    }

    /** Only https links to the configured Git host are kept, so a forged payload cannot plant an arbitrary link. */
    public function safeUrl(mixed $url): ?string
    {
        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $api = strtolower((string) parse_url((string) config('git.providers.github.api_url'), PHP_URL_HOST));
        $enterprise = $api !== '' && $api !== 'api.github.com' ? $api : null;

        return ($host === 'github.com' || str_ends_with($host, '.github.com') || ($enterprise !== null && $host === $enterprise)) ? Str::limit($url, 500, '') : null;
    }

    /** Bots (dependabot, renovate, the app itself) never drive task changes. */
    public function isBot(array $payload): bool
    {
        return strtolower((string) ($payload['sender']['type'] ?? '')) === 'bot' || str_ends_with(strtolower((string) ($payload['sender']['login'] ?? '')), '[bot]');
    }

    /** @param  array<string, mixed>  $payload */
    public function repoId(array $payload): int
    {
        return (int) ($payload['repository']['id'] ?? 0);
    }
}
