<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\TaskMoveRequest;
use App\Services\Workflow\MoveApprovals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Pending approvals for moves into gated statuses. Project members read; workflow managers decide. */
class TaskMoveRequestController extends Controller
{
    public function __construct(private readonly MoveApprovals $approvals) {}

    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $rows = TaskMoveRequest::query()->where('project_id', $project->id)->where('status', 'pending')
            ->with(['task:id,key,title', 'toStatus:id,name', 'fromStatus:id,name', 'requester:id,name'])->latest('id')->limit(100)->get();

        return response()->json(['requests' => $rows->map(fn ($r) => $this->present($r))->values(), 'can_decide' => request()->user()->can('manageWorkflow', $project)]);
    }

    public function approve(Request $request, Project $project, TaskMoveRequest $moveRequest): JsonResponse
    {
        $this->authorize('manageWorkflow', $project);
        abort_unless($moveRequest->project_id === $project->id, 404);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return response()->json(['message' => __('Move approved.'), 'request' => $this->present($this->approvals->approve($moveRequest, $request->user(), $data['note'] ?? null)->load(['task:id,key,title', 'toStatus:id,name', 'fromStatus:id,name', 'requester:id,name']))]);
    }

    public function reject(Request $request, Project $project, TaskMoveRequest $moveRequest): JsonResponse
    {
        $this->authorize('manageWorkflow', $project);
        abort_unless($moveRequest->project_id === $project->id, 404);
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);

        return response()->json(['message' => __('Move rejected.'), 'request' => $this->present($this->approvals->reject($moveRequest, $request->user(), $data['note'])->load(['task:id,key,title', 'toStatus:id,name', 'fromStatus:id,name', 'requester:id,name']))]);
    }

    public function withdraw(Request $request, Project $project, TaskMoveRequest $moveRequest): JsonResponse
    {
        abort_unless($moveRequest->project_id === $project->id, 404);
        abort_unless($moveRequest->requested_by === $request->user()->id || $request->user()->can('manageWorkflow', $project), 403);

        return response()->json(['message' => __('Request withdrawn.'), 'request' => $this->present($this->approvals->withdraw($moveRequest, $request->user())->load(['task:id,key,title', 'toStatus:id,name', 'fromStatus:id,name', 'requester:id,name']))]);
    }

    /** @return array<string, mixed> */
    private function present(TaskMoveRequest $r): array
    {
        return [
            'id' => $r->id,
            'status' => $r->status,
            'task' => ['id' => $r->task?->id, 'key' => $r->task?->key, 'title' => $r->task?->title],
            'from' => $r->fromStatus?->name,
            'to' => $r->toStatus?->name,
            'requested_by' => $r->requester?->name,
            'note' => $r->note,
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
