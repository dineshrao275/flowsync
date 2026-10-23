<?php

namespace App\Http\Controllers;

use App\Http\Requests\IssueTypeStoreRequest;
use App\Http\Requests\IssueTypeUpdateRequest;
use App\Models\IssueType;
use App\Services\IssueTypeService;
use Illuminate\Http\JsonResponse;

class IssueTypeController extends Controller
{
    public function __construct(
        private readonly IssueTypeService $service,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'issue_types' => $this->service->list(),
        ]);
    }

    public function store(IssueTypeStoreRequest $request): JsonResponse
    {
        $data = $request->validated();

        $issueType = $this->service->create($data);

        return response()->json([
            'message' => __('Issue type created.'),
            'issue_type' => $issueType,
        ], 201);
    }

    public function update(IssueTypeUpdateRequest $request, IssueType $issueType): JsonResponse
    {
        $data = $request->validated();

        $updated = $this->service->update($issueType, $data);

        return response()->json([
            'message' => __('Issue type updated.'),
            'issue_type' => $updated,
        ]);
    }

    public function destroy(IssueType $issueType): JsonResponse
    {
        $this->service->delete($issueType);

        return response()->json([
            'message' => __('Issue type deleted.'),
        ]);
    }
}
