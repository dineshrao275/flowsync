<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\Capacity\WorkloadService;
use App\Support\TaskScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkloadController extends Controller
{
    private const MAX_DAYS = 92;

    public function __construct(private readonly WorkloadService $service) {}

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        TaskScope::assertCanRead($project, $request->user());

        $data = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $from = CarbonImmutable::parse($data['from'] ?? now())->startOfWeek();
        $to = CarbonImmutable::parse($data['to'] ?? $from->addWeeks(4)->subDay())->endOfDay();

        abort_if($from->diffInDays($to) > self::MAX_DAYS, 422, __('Choose a range of at most :days days.', ['days' => self::MAX_DAYS]));

        return response()->json(['workload' => $this->service->forProject($project, $from, $to, $request->user())]);
    }
}
