<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\GitBranchRequest;
use App\Models\Project;
use App\Models\ProjectRepository;
use App\Models\Task;
use App\Models\Tenant;
use App\Services\Integrations\Git\GitBranchService;
use App\Services\Integrations\Git\GitProviderException;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;

/** Creates `feature/{KEY}-{slug}` on the linked repository from a task. */
class GitBranchController extends Controller
{
    public function __construct(private readonly GitBranchService $branches) {}

    public function store(GitBranchRequest $request, Project $project, ProjectRepository $link): JsonResponse
    {
        $this->authorize('settings', $project);
        abort_unless($link->project_id === $project->id, 404);
        $task = Task::query()->where('project_id', $project->id)->findOrFail($request->validated('task_id'));

        try {
            $branch = $this->branches->create(Tenant::query()->findOrFail(app(TenantContext::class)->currentId()), $project, $link, $task, $request->user(), $request);
        } catch (GitProviderException $e) {
            $response = response()->json(['message' => __('GitHub is not reachable right now. Try again shortly.')], $e->isRateLimited() ? 429 : 502);

            return $e->retryAfter !== null ? $response->header('Retry-After', (string) $e->retryAfter) : $response;
        }

        return response()->json([
            'message' => __('Branch created.'),
            'branch' => ['name' => $branch->name, 'html_url' => rtrim((string) $link->html_url, '/').'/tree/'.rawurlencode($branch->name)],
        ], 201);
    }
}
