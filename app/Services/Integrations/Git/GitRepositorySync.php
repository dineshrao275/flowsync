<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git;

use App\Models\GitConnection;
use App\Models\GitRepository;

/**
 * Mirrors the repositories an installation grants into `git_repositories`. Idempotent: rows are
 * upserted by `(connection, provider_repo_id)` and anything the installation no longer lists is
 * marked unavailable, never deleted, so a project link keeps its history.
 */
class GitRepositorySync
{
    public function __construct(private readonly GitProvider $provider) {}

    /** @return int how many repositories the installation currently grants */
    public function sync(GitConnection $connection): int
    {
        $seen = [];
        $page = 1;

        for ($i = 0; $i < GitHubAppClient::MAX_PAGES && $page !== null; $i++) {
            $result = $this->provider->installationRepositories((int) $connection->installation_id, $page);
            foreach ($result['repositories'] as $payload) {
                $seen[] = $this->upsert($connection, $payload);
            }
            $page = $result['next_page'];
        }

        GitRepository::query()->where('git_connection_id', $connection->id)
            ->when($seen !== [], fn ($q) => $q->whereNotIn('provider_repo_id', $seen))
            ->update(['available' => false]);

        $connection->forceFill(['last_synced_at' => now()])->save();

        return count($seen);
    }

    /** @param  array<string, mixed>  $payload */
    private function upsert(GitConnection $connection, array $payload): int
    {
        $id = (int) $payload['id'];
        $fullName = (string) ($payload['full_name'] ?? '');

        GitRepository::query()->updateOrCreate(
            ['git_connection_id' => $connection->id, 'provider_repo_id' => $id],
            [
                'provider' => $connection->provider,
                'full_name' => $fullName,
                'name' => (string) ($payload['name'] ?? ''),
                'owner' => (string) ($payload['owner']['login'] ?? strtok($fullName, '/')),
                'default_branch' => $payload['default_branch'] ?? null,
                'private' => (bool) ($payload['private'] ?? true),
                'archived' => (bool) ($payload['archived'] ?? false),
                'disabled' => (bool) ($payload['disabled'] ?? false),
                'available' => true,
                'html_url' => $payload['html_url'] ?? null,
                'clone_url' => $payload['clone_url'] ?? null,
                'ssh_url' => $payload['ssh_url'] ?? null,
                'permissions' => $payload['permissions'] ?? null,
                'pushed_at' => $payload['pushed_at'] ?? null,
                'provider_updated_at' => $payload['updated_at'] ?? null,
            ],
        );

        return $id;
    }
}
