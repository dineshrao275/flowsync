<?php

namespace App\Http\Controllers\Hrms\Talent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hrms\TalentCalibrateRequest;
use App\Models\Hrms\Performance\FeedbackRequest;
use App\Models\Hrms\Performance\PerformanceCycle;
use App\Models\Hrms\Performance\ReviewSummary;
use App\Services\Hrms\Talent\TalentCalibrationService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Talent/HRMS — the calibration room over HTTP.
 *
 * Validate → authorize → one service call → present. Every endpoint here
 * is calibrator-only (`hrms.talent.manage` via the policy): the grid names
 * reviewees and their ratings, so managers keep their own rooms and peers
 * never appear by name. The 360 summary carries numbers only — counts and
 * averages by relation, no bodies, no reviewer names; words stay behind
 * the per-request endpoint and its own policy.
 *
 * Ratings are 1-5, entered by humans: the distribution counts reviews per
 * value and never averages them into a person-score.
 */
class TalentController extends Controller
{
    public function __construct(
        private readonly TalentCalibrationService $calibration,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Every review in the cycle with its ratings and seal state, for the
     * calibration grid. Calibrators see everything — the audience gate is
     * the whole filter, so there is no per-row policy pass.
     */
    public function reviews(Request $request, PerformanceCycle $cycle): JsonResponse
    {
        $this->authorize('viewCalibration', ReviewSummary::class);

        $rows = $cycle->reviewSummaries()
            ->with(['employee:id,employee_code,name'])
            ->orderBy('id')
            ->get();

        return response()->json([
            'reviews' => $rows->map(fn (ReviewSummary $review): array => $this->row($review))->all(),
        ]);
    }

    public function calibrate(TalentCalibrateRequest $request, ReviewSummary $review): JsonResponse
    {
        $this->authorize('calibrate', $review);

        $calibrated = $this->calibration->calibrate($review, (int) $request->validated('rating'), $request->user());

        return response()->json([
            'message' => 'Review calibrated.',
            'review' => $this->row($calibrated),
        ]);
    }

    public function finalize(Request $request, ReviewSummary $review): JsonResponse
    {
        $this->authorize('finalize', $review);

        $final = $this->calibration->finalize($review, $request->user());

        // The receipt lands where the person it is about can see it —
        // skipped for the login-less and for self-finalizes, like every
        // other nudge.
        $this->notifications->talentReviewFinalized($final, $request->user());

        return response()->json([
            'message' => 'Review finalized.',
            'review' => $this->row($final),
        ]);
    }

    /**
     * The calibration shape: how many reviews sit on each rating value,
     * plus how many are unrated and where each seal state stands. Counts
     * only — the grid beside it already names the names.
     */
    public function distribution(Request $request, PerformanceCycle $cycle): JsonResponse
    {
        $this->authorize('viewCalibration', ReviewSummary::class);

        $reviews = $cycle->reviewSummaries()->get(['manager_rating', 'calibrated_rating', 'status']);

        $byRating = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $unrated = 0;

        foreach ($reviews as $review) {
            $effective = $review->calibrated_rating ?? $review->manager_rating;

            if ($effective === null) {
                $unrated++;
            } else {
                $byRating[(int) $effective]++;
            }
        }

        $byStatus = [];
        foreach ($reviews as $review) {
            $status = $review->status->value;
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
        }

        return response()->json([
            'distribution' => [
                'by_rating' => $byRating,
                'unrated' => $unrated,
                'by_status' => $byStatus,
                'total' => $reviews->count(),
            ],
        ]);
    }

    /**
     * The 360 signal per reviewee: response counts and averages by
     * relation (manager/peer/direct_report), computed over rated
     * responses only. Numbers travel; reviewer names and bodies do not —
     * peer anonymity survives aggregation.
     */
    public function feedbackSummary(Request $request, PerformanceCycle $cycle): JsonResponse
    {
        $this->authorize('viewCalibration', ReviewSummary::class);

        $requests = FeedbackRequest::query()
            ->where('cycle_id', $cycle->id)
            ->with(['responses', 'to:id,employee_code,name'])
            ->get();

        $summaries = [];
        foreach ($requests->groupBy('to_employee_id') as $employeeId => $asks) {
            $responses = $asks->flatMap(fn (FeedbackRequest $ask) => $ask->responses->map(
                fn ($response) => ['relation' => $ask->relation->value, 'rating' => $response->rating]
            ));

            $byRelation = [];
            foreach ($responses->groupBy('relation') as $relation => $rows) {
                $rated = $rows->whereNotNull('rating')->pluck('rating')->map(fn ($rating): int => (int) $rating);

                $byRelation[$relation] = [
                    'count' => $rows->count(),
                    'rated_count' => $rated->count(),
                    'average_rating' => $rated->isNotEmpty() ? round($rated->avg(), 2) : null,
                ];
            }

            $first = $asks->first();
            $summaries[] = [
                'employee_id' => (int) $employeeId,
                'employee' => $first->to === null ? null : [
                    'id' => $first->to->id,
                    'employee_code' => $first->to->employee_code,
                    'name' => $first->to->displayName(),
                ],
                'by_relation' => $byRelation,
            ];
        }

        return response()->json([
            'feedback_summary' => array_values($summaries),
        ]);
    }

    /**
     * One calibration-grid row: identifiers, both ratings, the seal state.
     * No visibility split — the audience gate already decided.
     *
     * @return array<string, mixed>
     */
    private function row(ReviewSummary $review): array
    {
        $review->loadMissing(['employee:id,employee_code,name']);

        return [
            'id' => $review->id,
            'cycle_id' => $review->cycle_id,
            'employee_id' => $review->employee_id,
            'employee' => $review->employee === null ? null : [
                'id' => $review->employee->id,
                'employee_code' => $review->employee->employee_code,
                'name' => $review->employee->displayName(),
            ],
            'self_rating' => $review->self_rating,
            'manager_rating' => $review->manager_rating,
            'calibrated_rating' => $review->calibrated_rating,
            'calibrated_at' => $review->calibrated_at?->toIso8601String(),
            'visibility_to_employee' => $review->visibility_to_employee->value,
            'status' => $review->status->value,
        ];
    }
}
