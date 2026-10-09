<?php

namespace App\Http\Controllers\Hrms\Performance;

use App\Http\Controllers\Concerns\ChecksPerformanceScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\FeedbackRespondRequest;
use App\Models\Hrms\Performance\FeedbackRequest;
use App\Models\Hrms\Performance\PerformanceCycle;
use App\Services\Hrms\HrmsScope;
use App\Services\Hrms\Performance\FeedbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Performance/HRMS — feedback asks and their answers over HTTP.
 *
 * Reading takes the reviewer, the reviewee, or the view permission; what
 * each of them sees is the service's anonymity tiers, not a second policy.
 * Answering takes the requested reviewer alone — the one ability no
 * permission grants, because nobody responds on another person's behalf.
 */
class FeedbackRequestController extends Controller
{
    use ChecksPerformanceScope;

    public function __construct(private readonly FeedbackService $feedback) {}

    public function index(Request $request, PerformanceCycle $cycle): JsonResponse
    {
        $this->authorize('viewAny', FeedbackRequest::class);

        $query = $cycle->feedbackRequests()
            ->with(['from:id,employee_code,name,user_id', 'to:id,employee_code,name,user_id', 'responses'])
            ->orderBy('id');

        if (! $this->seesAll($request->user())) {
            $ids = HrmsScope::employeeIdsFor($request->user(), 'hrms.performance');
            $query->where(fn ($nested) => $nested
                ->whereIn('from_employee_id', $ids)
                ->orWhereIn('to_employee_id', $ids));
        }

        if ($request->has('employee_id')) {
            $id = (int) $request->query('employee_id');
            $query->where(fn ($nested) => $nested
                ->where('from_employee_id', $id)
                ->orWhere('to_employee_id', $id));
        }

        return response()->json([
            'feedback_requests' => $query->get()
                ->map(fn (FeedbackRequest $row): array => $this->feedback->presentRequest($row, $request->user()))
                ->all(),
        ]);
    }

    public function show(Request $request, FeedbackRequest $feedbackRequest): JsonResponse
    {
        $this->authorize('view', $feedbackRequest);

        return response()->json([
            'feedback_request' => $this->feedback->presentRequest($feedbackRequest, $request->user()),
        ]);
    }

    public function respond(FeedbackRespondRequest $request, FeedbackRequest $feedbackRequest): JsonResponse
    {
        $this->authorize('respond', $feedbackRequest);

        $answered = $this->feedback->respond($feedbackRequest, $request->validated(), $request->user());

        return response()->json([
            'message' => ($request->validated()['action'] ?? null) === 'decline'
                ? 'Feedback declined.'
                : 'Feedback submitted.',
            'feedback_request' => $answered instanceof FeedbackRequest
                ? $this->feedback->presentRequest($answered, $request->user())
                : $this->feedback->presentRequest($feedbackRequest->refresh(), $request->user()),
        ]);
    }
}
