<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git;

use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\ProjectRepository;
use App\Models\Tenant;
use App\Support\Like;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** What a tenant may pick from: the available repositories of its own active connections, and nothing else. */
class GitRepositoryCatalog
{
    public const PER_PAGE = 25;

    public function search(Tenant $tenant, ?string $term, ?string $connectionUuid = null): LengthAwarePaginator
    {
        $query = GitRepository::query()->with('connection:id,uuid,account_login')
            ->where('available', true)
            ->whereIn('git_connection_id', $this->connectionIds($tenant, $connectionUuid))
            ->orderBy('full_name');

        if ($term !== null && trim($term) !== '') {
            Like::any($query, ['full_name'], trim($term));
        }

        return $query->paginate(self::PER_PAGE);
    }

    /** Resolves a repository only if it belongs to one of this tenant's active connections. */
    public function find(Tenant $tenant, string $uuid): GitRepository
    {
        return GitRepository::query()->with('connection')->where('uuid', $uuid)
            ->whereIn('git_connection_id', $this->connectionIds($tenant))->first()
            ?? throw new GitConnectionException(__('Repository not found.'), 404);
    }

    /** The catalog row a project link follows, still restricted to this tenant's active connections. */
    public function findForLink(Tenant $tenant, ProjectRepository $link): GitRepository
    {
        $connection = GitConnection::query()->where('tenant_id', $tenant->id)->where('uuid', $link->git_connection_uuid)->first();

        return GitRepository::query()->with('connection')->where('git_connection_id', $connection?->id ?? 0)
            ->where('provider_repo_id', $link->provider_repo_id)->first()
            ?? throw new GitConnectionException(__('Repository not found.'), 404);
    }

    /** @return list<int> */
    private function connectionIds(Tenant $tenant, ?string $uuid = null): array
    {
        return GitConnection::query()->where('tenant_id', $tenant->id)->where('status', GitConnection::STATUS_ACTIVE)
            ->when($uuid !== null, fn ($q) => $q->where('uuid', $uuid))->pluck('id')->all();
    }
}
