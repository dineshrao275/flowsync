<?php

namespace App\Services\Hrms\Talent;

use App\Enums\Hrms\PerformanceCycleStage;
use App\Enums\Hrms\ReviewStatus;
use App\Models\Hrms\Performance\ReviewSummary;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HRMS Talent — the calibration room (Phase 24).
 *
 * The cycle machine only opens calibration; this service works it.
 * Calibrating adjusts a review without rewriting history: the manager's
 * original `manager_rating` stays, and the calibrated outcome lands beside
 * it with who/when. Finalizing seals the review — final is a seal, not a
 * toggle, and the employee's notification is a controller-side effect
 * (P24.5), like every other notifier in this codebase.
 *
 * Ratings are 1-5, entered by humans, one replacing another: there is no
 * averaging, no weighting and no composite anywhere in this file, per the
 * founding rule on `ReviewSummary`.
 */
class TalentCalibrationService
{
    public function __construct(private readonly HrmsAuditLogger $audit) {}

    /**
     * @throws ValidationException on a non-1-to-5 rating or a wrong stage
     */
    public function calibrate(ReviewSummary $review, int $rating, ?User $actor = null): ReviewSummary
    {
        if ($rating < 1 || $rating > 5) {
            throw ValidationException::withMessages([
                'rating' => 'A calibrated rating is 1–5, entered by a human — never computed.',
            ]);
        }

        // Normalize a freshly-created model: `create()` does not apply the
        // DB defaults (`status = draft`) to the in-memory attributes, and
        // the snapshot below reads them.
        $review->refresh();

        $this->requireCalibrationStage($review);
        $this->requireUnsealed($review);

        return DB::transaction(function () use ($review, $rating, $actor): ReviewSummary {
            $before = $this->snapshot($review);
            $review->update([
                'calibrated_rating' => $rating,
                'calibrated_by' => $actor?->id,
                'calibrated_at' => now(),
                'status' => ReviewStatus::Calibrating,
            ]);

            $this->audit->log($review->refresh(), 'talent.review_calibrated', $before, $this->snapshot($review->refresh()), $actor);

            return $review->refresh();
        });
    }

    /**
     * @throws ValidationException outside calibration, or on an already-sealed review
     */
    public function finalize(ReviewSummary $review, ?User $actor = null): ReviewSummary
    {
        $review->refresh();

        $this->requireCalibrationStage($review);
        $this->requireUnsealed($review);

        return DB::transaction(function () use ($review, $actor): ReviewSummary {
            $before = $this->snapshot($review);
            $review->update(['status' => ReviewStatus::Final]);

            $this->audit->log($review->refresh(), 'talent.review_finalized', $before, $this->snapshot($review->refresh()), $actor);

            return $review->refresh();
        });
    }

    /**
     * @throws ValidationException on any other stage
     */
    private function requireCalibrationStage(ReviewSummary $review): void
    {
        $stage = $review->cycle->stage;

        if ($stage !== PerformanceCycleStage::Calibration) {
            throw ValidationException::withMessages([
                'stage' => "That cycle is {$stage->value}, not calibration — reviews calibrate from calibration.",
            ]);
        }
    }

    /**
     * A sealed review stays sealed: recalibrating a final write-up would
     * silently reopen it behind the employee's acknowledgement.
     *
     * @throws ValidationException on a final or acknowledged review
     */
    private function requireUnsealed(ReviewSummary $review): void
    {
        if (in_array($review->status, [ReviewStatus::Final, ReviewStatus::Acknowledged], true)) {
            throw ValidationException::withMessages([
                'form' => 'That review is already final — final is a seal, not a toggle.',
            ]);
        }
    }

    /**
     * Identifiers and states only; values are masked at the writer anyway.
     */
    private function snapshot(ReviewSummary $review): array
    {
        return [
            'cycle_id' => $review->cycle_id,
            'employee_id' => $review->employee_id,
            'status' => $review->status->value,
            'manager_rating' => $review->manager_rating,
            'calibrated_rating' => $review->calibrated_rating,
        ];
    }
}
