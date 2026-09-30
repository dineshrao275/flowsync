<?php

namespace App\Http\Controllers\Hrms\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\PerformanceCycleRequest;
use App\Models\Hrms\Performance\PerformanceCycle;
use App\Services\Hrms\Performance\PerformanceCycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Performance/HRMS — cycles and their stage machine over HTTP.
 *
 * Thin: it authorizes (reads on view, every move on manage), hands the
 * transition to the workflow service, and shapes the envelope. The order
 * rules and the weight totals live in the service — including the five
 * explicit transition endpoints, which name the move instead of taking a
 * `stage` parameter a client could point anywhere.
 */
class PerformanceCycleController extends Controller
{
    public function __construct(private readonly PerformanceCycleService $cycles) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', PerformanceCycle::class);

        return response()->json([
            'cycles' => PerformanceCycle::query()->orderByDesc('period_start')
                ->get()->map(fn (PerformanceCycle $cycle): array => $this->present($cycle))->all(),
        ]);
    }

    public function show(PerformanceCycle $cycle): JsonResponse
    {
        $this->authorize('view', $cycle);

        return response()->json(['cycle' => $this->present($cycle)]);
    }

    public function store(PerformanceCycleRequest $request): JsonResponse
    {
        $this->authorize('create', PerformanceCycle::class);

        $cycle = $this->cycles->createCycle($request->validated(), $request->user());

        return response()->json([
            'message' => 'Cycle created.',
            'cycle' => $this->present($cycle),
        ], Response::HTTP_CREATED);
    }

    public function openCheckIn(Request $request, PerformanceCycle $cycle): JsonResponse
    {
        $this->authorize('transition', $cycle);

        return response()->json([
            'message' => 'Check-ins opened.',
            'cycle' => $this->present($this->cycles->openCheckIn($cycle, $request->user())),
        ]);
    }

    public function openSelfReview(Request $request, PerformanceCycle $cycle): JsonResponse
    {
        $this->authorize('transition', $cycle);

        return response()->json([
            'message' => 'Self review opened.',
            'cycle' => $this->present($this->cycles->openSelfReview($cycle, $request->user())),
        ]);
    }

    public function openManagerReview(Request $request, PerformanceCycle $cycle): JsonResponse
    {
        $this->authorize('transition', $cycle);

        return response()->json([
            'message' => 'Manager review opened.',
            'cycle' => $this->present($this->cycles->openManagerReview($cycle, $request->user())),
        ]);
    }

    public function openCalibration(Request $request, PerformanceCycle $cycle): JsonResponse
    {
        $this->authorize('transition', $cycle);

        return response()->json([
            'message' => 'Calibration opened.',
            'cycle' => $this->present($this->cycles->openCalibration($cycle, $request->user())),
        ]);
    }

    public function complete(Request $request, PerformanceCycle $cycle): JsonResponse
    {
        $this->authorize('transition', $cycle);

        return response()->json([
            'message' => 'Cycle completed.',
            'cycle' => $this->present($this->cycles->complete($cycle, $request->user())),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PerformanceCycle $cycle): array
    {
        return [
            'id' => $cycle->id,
            'name' => $cycle->name,
            'slug' => $cycle->slug,
            'description' => $cycle->description,
            'period_start' => $cycle->period_start->toDateString(),
            'period_end' => $cycle->period_end->toDateString(),
            'stage' => $cycle->stage->value,
            'anonymity' => $cycle->anonymity,
            'is_active' => $cycle->is_active,
        ];
    }
}
