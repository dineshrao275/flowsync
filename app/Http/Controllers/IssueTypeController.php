<?php

namespace App\Http\Controllers;

use App\Services\IssueTypeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IssueTypeController extends Controller
{
    public function __construct(
        private readonly IssueTypeService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'issue_types' => $this->service->list(),
        ]);
    }
}
