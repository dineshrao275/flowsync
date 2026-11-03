<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git;

use App\Models\GitRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Reads live repository data through the provider with a short cache, so a page of tasks or a busy
 * picker never turns into one GitHub call per viewer. Every list is a single capped page.
 */
class GitRepositoryBrowser
{
    public const TTL_BRANCHES = 60;

    public const TTL_COMMITS = 60;

    public const TTL_PULLS = 60;

    public const TTL_CHECKS = 30;

    public function __construct(private readonly GitProvider $provider) {}

    /** @return list<array<string, mixed>> */
    public function branches(GitRepository $repository): array
    {
        return $this->cached($repository, 'branches', self::TTL_BRANCHES, fn (int $id) => $this->provider->branches($id, $repository->full_name));
    }

    /** @return list<array<string, mixed>> */
    public function commits(GitRepository $repository, ?string $branch = null): array
    {
        return $this->cached($repository, 'commits:'.($branch ?? '-'), self::TTL_COMMITS, fn (int $id) => $this->provider->commits($id, $repository->full_name, $branch));
    }

    /** @return list<array<string, mixed>> */
    public function pullRequests(GitRepository $repository, string $state = 'all'): array
    {
        return $this->cached($repository, 'pulls:'.$state, self::TTL_PULLS, fn (int $id) => $this->provider->pullRequests($id, $repository->full_name, $state));
    }

    public function checks(GitRepository $repository, string $ref): string
    {
        return $this->cached($repository, 'checks:'.$ref, self::TTL_CHECKS, fn (int $id) => $this->provider->checks($id, $repository->full_name, $ref));
    }

    private function cached(GitRepository $repository, string $kind, int $ttl, callable $fetch): mixed
    {
        $connection = $repository->connection;
        if ($connection === null || ! $connection->isActive() || ! $repository->available) {
            throw new GitConnectionException(__('This repository is not available.'), 404);
        }

        // Keyed by repository row so two tenants with a same-named repository never share an entry.
        return Cache::remember("git:browse:{$repository->id}:{$kind}", $ttl, fn () => $fetch((int) $connection->installation_id));
    }
}
