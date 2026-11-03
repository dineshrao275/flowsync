<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Integrations\Git\GitConnectionException;
use App\Services\Integrations\Git\GitProviderException;
use App\Services\Integrations\Git\GitRepositoryBrowser;
use App\Services\Integrations\Git\GitRepositoryCatalog;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The repository picker and read-only browsing (branches, commits, pull requests) for a tenant's own repositories. */
class GitRepositoryController extends Controller
{
    public function __construct(
        private readonly GitRepositoryCatalog $catalog,
        private readonly GitRepositoryBrowser $browser,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'connection' => ['nullable', 'uuid']]);
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

    public function commits(Request $request, string $uuid): JsonResponse
    {
        $branch = $request->validate(['branch' => ['nullable', 'string', 'max:255']])['branch'] ?? null;

        return $this->read($uuid, 'commits', fn ($repo) => $this->browser->commits($repo, $branch));
    }

    public function pullRequests(Request $request, string $uuid): JsonResponse
    {
        $state = $request->validate(['state' => ['nullable', 'in:open,closed,all']])['state'] ?? 'all';

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
