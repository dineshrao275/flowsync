<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AiProposalStoreRequest;
use App\Http\Requests\AiProposalUpdateRequest;
use App\Models\Project;
use App\Services\Ai\AiErrorPresenter;
use App\Services\Ai\AiProposalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class AiProposalController extends Controller
{
    public function __construct(private readonly AiProposalService $proposals) {}

    public function store(AiProposalStoreRequest $request): JsonResponse
    {
        $input = $request->validated();

        // A project the caller cannot see answers exactly like one that does not exist.
        $project = Project::find($input['project_id']);
        abort_if($project === null || $request->user()->cannot('view', $project), 404);
        $this->authorize('createTask', $project);

        try {
            $proposal = $this->proposals->generate($request->user(), $project, $input['document_id'] ?? null, $input['text'] ?? null, $input['instructions'] ?? null);
        } catch (Throwable $e) {
            if ($e instanceof ValidationException) {
                throw $e;
            }
            $presented = AiErrorPresenter::present($e);

            return response()->json($presented['body'], $presented['status']);
        }

        return response()->json(['proposal' => $this->proposals->present($proposal)], 201);
    }

    public function show(Request $request, string $proposal): JsonResponse
    {
        return response()->json(['proposal' => $this->proposals->present($this->proposals->find($request->user(), $proposal))]);
    }

    public function update(AiProposalUpdateRequest $request, string $proposal): JsonResponse
    {
        return response()->json(['proposal' => $this->proposals->present($this->proposals->update($request->user(), $proposal, $request->validated('items')))]);
    }

    public function confirm(Request $request, string $proposal): JsonResponse
    {
        $result = $this->proposals->confirm($request->user(), $proposal, $request->ip());

        return response()->json([
            'message' => __('Tasks created.'),
            'proposal' => $this->proposals->present($result),
        ]);
    }

    public function reject(Request $request, string $proposal): JsonResponse
    {
        return response()->json(['proposal' => $this->proposals->present($this->proposals->reject($request->user(), $proposal))]);
    }
}
