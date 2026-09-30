<?php

namespace App\Http\Controllers\Hrms\Performance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\ReviewSummaryRequest;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Performance\PerformanceCycle;
use App\Models\Hrms\Performance\ReviewSummary;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Performance/HRMS — review write-ups over HTTP.
 *
 * Filing and editing take the manager or manage; reading splits by
 * visibility (the policy) and filters by it too (the presenter): the owner
 * always sees their own words, the manager's half only once shared.
 * Acknowledging seals the write-up and notifies the employee — the
 * receipt lands where the person it is about can see it.
 */
class ReviewSummaryController extends Controller
{
    public function __construct(
        private readonly HrmsAuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    public function index(Request $request, PerformanceCycle $cycle): JsonResponse
    {
        $this->authorize('viewAny', ReviewSummary::class);

        $query = $cycle->reviewSummaries()->with(['employee:id,employee_code,name,user_id'])->orderBy('id');

        if ($request->has('employee_id')) {
            $query->where('employee_id', (int) $request->query('employee_id'));
        }

        $rows = $query->get()->filter(fn (ReviewSummary $review): bool => $request->user()->can('view', $review));

        return response()->json([
            'reviews' => $rows->map(fn (ReviewSummary $review): array => $this->present($review, $request->user()))->values()->all(),
        ]);
    }

    public function show(Request $request, ReviewSummary $review): JsonResponse
    {
        $this->authorize('view', $review);

        return response()->json(['review' => $this->present($review, $request->user())]);
    }

    public function store(ReviewSummaryRequest $request, PerformanceCycle $cycle): JsonResponse
    {
        $data = $request->validated();
        $employee = Employee::findOrFail((int) $data['employee_id']);

        $this->authorize('file', [ReviewSummary::class, $employee]);
        abort_if($cycle->stage->value === 'completed', 422, 'That cycle is sealed — history gains no new reviews.');

        $review = $cycle->reviewSummaries()->create($request->validated());

        $this->audit->log($review, 'performance.review_filed', null, [
            'employee_id' => $review->employee_id,
        ], $request->user());

        return response()->json([
            'message' => 'Review filed.',
            'review' => $this->present($review->refresh(), $request->user()),
        ], Response::HTTP_CREATED);
    }

    public function update(ReviewSummaryRequest $request, ReviewSummary $review): JsonResponse
    {
        $this->authorize('update', $review);

        $data = $request->validated();
        unset($data['employee_id']);

        $review->update($data);

        $this->audit->log($review->refresh(), 'performance.review_updated', null, [
            'employee_id' => $review->employee_id,
            'status' => $review->status->value,
        ], $request->user());

        return response()->json([
            'message' => 'Review updated.',
            'review' => $this->present($review->refresh(), $request->user()),
        ]);
    }

    public function acknowledge(Request $request, ReviewSummary $review): JsonResponse
    {
        $this->authorize('acknowledge', $review);

        return DB::transaction(function () use ($request, $review): JsonResponse {
            $review->update(['status' => 'acknowledged', 'acknowledged_at' => now()]);

            $notified = $this->notifications->performanceReviewAcknowledged($review->refresh(), $request->user());

            $this->audit->log($review->refresh(), 'performance.review_acknowledged', null, [
                'employee_id' => $review->employee_id,
                'notified' => $notified !== null,
            ], $request->user());

            return response()->json([
                'message' => 'Review acknowledged.',
                'review' => $this->present($review->refresh(), $request->user()),
            ]);
        });
    }

    /**
     * One write-up, filtered for the viewer: the owner always reads their
     * own words, the manager's rating and comments only once shared, and
     * everyone else reads what the policy already let through.
     *
     * @return array<string, mixed>
     */
    private function present(ReviewSummary $review, User $viewer): array
    {
        $review->loadMissing(['employee:id,employee_code,name,user_id', 'manager:id,employee_code,name']);

        $isOwner = $review->employee !== null
            && $review->employee->user_id !== null
            && (int) $review->employee->user_id === (int) $viewer->id;

        $managerVisible = $review->visibility_to_employee->value === 'shared'
            || ! $isOwner
            || $viewer->hasPermission('hrms.talent.manage')
            || $viewer->hasPermission('hrms.performance.manage');

        $person = fn ($record): ?array => $record === null ? null : [
            'id' => $record->id,
            'employee_code' => $record->employee_code,
            'name' => $record->displayName(),
        ];

        return [
            'id' => $review->id,
            'cycle_id' => $review->cycle_id,
            'employee_id' => $review->employee_id,
            'employee' => $person($review->employee),
            'manager' => $person($review->manager),
            'self_rating' => $review->self_rating,
            'manager_rating' => $managerVisible ? $review->manager_rating : null,
            'strengths' => $review->strengths,
            'improvements' => $review->improvements,
            'manager_comments' => $managerVisible ? $review->manager_comments : null,
            'evidence_snapshot' => $review->evidence_snapshot,
            'visibility_to_employee' => $review->visibility_to_employee->value,
            'status' => $review->status->value,
            'submitted_at' => $review->submitted_at?->toIso8601String(),
            'acknowledged_at' => $review->acknowledged_at?->toIso8601String(),
        ];
    }
}
