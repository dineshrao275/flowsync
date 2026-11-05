<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\GitRepositoryBrowseRequest;
use App\Models\Tenant;
use App\Services\Integrations\Git\GitConnectionException;
use App\Services\Integrations\Git\GitProviderException;
use App\Services\Integrations\Git\GitRepositoryBrowser;
use App\Services\Integrations\Git\GitRepositoryCatalog;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;

/** The repository picker and read-only browsing (branches, commits, pull requests) for a tenant's own repositories. */
class GitRepositoryController extends Controller
{
    public function __construct(
        private readonly GitRepositoryCatalog $catalog,
        private readonly GitRepositoryBrowser $browser,
    ) {}

    public function index(GitRepositoryBrowseRequest $request): JsonResponse
    {
        $data = $request->validated();
        $page = $this->catalog->search($this->tenant(), $data['q'] ?? null, $data['connection'] ?? null);

        return response()->json([
            'repositories' => $page->getCollection()->map(fn ($r) => [
                'uuid' => $r->uuid, 'full_name' => $r->full_name, 'name' => $r->name, 'default_branch' => $r->default_branch,
                'private' => $r->private, 'archived' => $r->archived, 'html_url' => $r->html_url,
                'connection' => ['uuid' => $r->connection->uuid, 'account_login' => $r->connection->account_login],
            ])->values(),
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function branches(string $uuid): JsonResponse
    {
        return $this->read($uuid, 'branches', fn ($repo) => $this->browser->branches($repo));
    }

    public function commits(GitRepositoryBrowseRequest $request, string $uuid): JsonResponse
    {
        $branch = $request->validated('branch');

        return $this->read($uuid, 'commits', fn ($repo) => $this->browser->commits($repo, $branch));
    }

    public function pullRequests(GitRepositoryBrowseRequest $request, string $uuid): JsonResponse
    {
        $state = $request->validated('state') ?? 'all';

        return $this->read($uuid, 'pull_requests', fn ($repo) => $this->browser->pullRequests($repo, $state));
    }

    private function read(string $uuid, string $key, callable $load): JsonResponse
    {
        try {
            return response()->json([$key => $load($this->catalog->find($this->tenant(), $uuid))]);
        } catch (GitConnectionException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        } catch (GitProviderException $e) {
            $response = response()->json(['message' => __('GitHub is not reachable right now. Try again shortly.')], $e->isRateLimited() ? 429 : 502);

            return $e->retryAfter !== null ? $response->header('Retry-After', (string) $e->retryAfter) : $response;
        }
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->findOrFail(app(TenantContext::class)->currentId());
    }
}
