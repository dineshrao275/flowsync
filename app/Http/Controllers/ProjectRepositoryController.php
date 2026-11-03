<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\GitRepositoryBrowseRequest;
use App\Http\Requests\ProjectRepositoryRequest;
use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\Tenant;
use App\Services\Integrations\Git\GitConnectionException;
use App\Services\Integrations\Git\GitProviderException;
use App\Services\Integrations\Git\GitRepositoryBrowser;
use App\Services\Integrations\Git\GitRepositoryCatalog;
use App\Services\Integrations\Git\ProjectRepositoryService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The repositories a project follows. Reading needs project access + git.view; changing needs project settings + git.manage. */
class ProjectRepositoryController extends Controller
{
    public function __construct(
        private readonly ProjectRepositoryService $links,
        private readonly GitRepositoryCatalog $catalog,
        private readonly GitRepositoryBrowser $browser,
    ) {}

    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json(['repositories' => array_map($this->present(...), $this->links->list($project))]);
    }

    public function store(ProjectRepositoryRequest $request, Project $project): JsonResponse
    {
        $this->authorize('settings', $project);
        $link = $this->links->link($this->tenant(), $project, $request->validated(), $request->user());

        return response()->json(['message' => __('Repository linked.'), 'repository' => $this->present($link)], 201);
    }

    public function update(ProjectRepositoryRequest $request, Project $project, ProjectRepository $link): JsonResponse
    {
        $this->authorize('settings', $project);
        $this->assertBelongs($project, $link);

        return response()->json(['message' => __('Repository settings saved.'), 'repository' => $this->present($this->links->update($link, $request->validated()))]);
    }

    public function destroy(Request $request, Project $project, ProjectRepository $link): JsonResponse
    {
        $this->authorize('settings', $project);
        $this->assertBelongs($project, $link);
        $this->links->unlink($project, $link, $request->boolean('remove_linked_data'));

        return response()->json(['message' => __('Repository unlinked.')]);
    }

    public function branches(Project $project, ProjectRepository $link): JsonResponse
    {
        return $this->read($project, $link, 'branches', fn ($repo) => $this->browser->branches($repo));
    }

    public function commits(GitRepositoryBrowseRequest $request, Project $project, ProjectRepository $link): JsonResponse
    {
        $branch = $request->validated('branch');

        return $this->read($project, $link, 'commits', fn ($repo) => $this->browser->commits($repo, $branch));
    }

    public function pullRequests(GitRepositoryBrowseRequest $request, Project $project, ProjectRepository $link): JsonResponse
    {
        $state = $request->validated('state') ?? 'all';

        return $this->read($project, $link, 'pull_requests', fn ($repo) => $this->browser->pullRequests($repo, $state));
    }

    private function read(Project $project, ProjectRepository $link, string $key, callable $load): JsonResponse
    {
        $this->authorize('view', $project);
        $this->assertBelongs($project, $link);

        try {
            return response()->json([$key => $load($this->catalog->findForLink($this->tenant(), $link))]);
        } catch (GitConnectionException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        } catch (GitProviderException $e) {
            $response = response()->json(['message' => __('GitHub is not reachable right now. Try again shortly.')], $e->isRateLimited() ? 429 : 502);

            return $e->retryAfter !== null ? $response->header('Retry-After', (string) $e->retryAfter) : $response;
        }
    }

    private function assertBelongs(Project $project, ProjectRepository $link): void
    {
        abort_unless($link->project_id === $project->id, 404);
    }

    /** @return array<string, mixed> */
    private function present(ProjectRepository $link): array
    {
        return [
            'id' => $link->id, 'provider' => $link->provider, 'full_name' => $link->full_name, 'default_branch' => $link->default_branch,
            'html_url' => $link->html_url, 'sync_enabled' => $link->sync_enabled, 'smart_commits_enabled' => $link->smart_commits_enabled,
            'smart_commit_transitions_enabled' => $link->smart_commit_transitions_enabled,
            'allow_cross_project' => $link->allow_cross_project, 'linked_at' => $link->linked_at?->toIso8601String(),
        ];
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->findOrFail(app(TenantContext::class)->currentId());
    }
}
