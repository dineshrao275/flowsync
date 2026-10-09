<?php

namespace App\Http\Controllers;

use App\Models\IssueType;
use App\Services\IssueTypeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:50'],
            'is_subtask' => ['nullable', 'boolean'],
            'hierarchy_level' => ['nullable', 'integer', 'between:0,2'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        $issueType = $this->service->create($data);

        return response()->json([
            'message' => 'Issue type created.',
            'issue_type' => $issueType,
        ], 201);
    }

    public function update(Request $request, IssueType $issueType): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:50'],
            'is_subtask' => ['nullable', 'boolean'],
            'hierarchy_level' => ['nullable', 'integer', 'between:0,2'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        $updated = $this->service->update($issueType, $data);

        return response()->json([
            'message' => 'Issue type updated.',
            'issue_type' => $updated,
        ]);
    }

    public function destroy(IssueType $issueType): JsonResponse
    {
        $this->service->delete($issueType);

        return response()->json([
            'message' => 'Issue type deleted.',
        ]);
    }
}
