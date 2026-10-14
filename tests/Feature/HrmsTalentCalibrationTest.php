<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Enums\Hrms\PerformanceCycleStage;
use App\Enums\Hrms\ReviewStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Performance\PerformanceCycle;
use App\Models\Hrms\Performance\ReviewSummary;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Models\User;
use App\Services\Hrms\Talent\TalentCalibrationService;
use Illuminate\Validation\ValidationException;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * Phase 24.1 — the calibration room.
 *
 * The cycle machine opens calibration; this service works it. Calibrating
 * records the outcome beside the manager's untouched original, and
 * finalizing seals the review. Both refuse the wrong stage, and ratings
 * are 1-5 humans, never computed.
 */
class HrmsTalentCalibrationTest extends TestCase
{
    use IsolatesDatabase;

    public function test_calibrate_refuses_any_stage_but_calibration(): void
    {
        $review = $this->reviewInStage(PerformanceCycleStage::ManagerReview);

        try {
            app(TalentCalibrationService::class)->calibrate($review, 4, $this->hr());
            $this->fail('Calibrating outside the calibration stage must refuse.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('stage', $e->errors());
        }
    }

    public function test_calibrate_rejects_ratings_outside_1_to_5(): void
    {
        $review = $this->reviewInStage(PerformanceCycleStage::Calibration);
        $hr = $this->hr();

        foreach ([0, 6] as $rating) {
            try {
                app(TalentCalibrationService::class)->calibrate($review, $rating, $hr);
                $this->fail("Rating {$rating} must refuse.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('rating', $e->errors());
            }
        }
    }

    public function test_calibrate_records_beside_the_untouched_original(): void
    {
        $hr = $this->hr();
        $review = $this->reviewInStage(PerformanceCycleStage::Calibration, ['manager_rating' => 3]);

        $calibrated = app(TalentCalibrationService::class)->calibrate($review, 4, $hr);

        $this->assertSame(ReviewStatus::Calibrating, $calibrated->status);
        $this->assertSame(4, $calibrated->calibrated_rating);
        $this->assertSame($hr->id, $calibrated->calibrated_by);
        $this->assertNotNull($calibrated->calibrated_at);
        // History is not rewritten: the manager's original stays.
        $this->assertSame(3, $calibrated->manager_rating);

        $this->assertTrue(HrmsAuditLog::query()
            ->where('action', 'talent.review_calibrated')
            ->where('subject_id', $review->id)
            ->exists());
    }

    public function test_finalize_seals_and_refuses_a_reseal(): void
    {
        $review = $this->reviewInStage(PerformanceCycleStage::Calibration);
        $hr = $this->hr();

        $final = app(TalentCalibrationService::class)->finalize($review, $hr);

        $this->assertSame(ReviewStatus::Final, $final->status);
        $this->assertTrue(HrmsAuditLog::query()
            ->where('action', 'talent.review_finalized')
            ->where('subject_id', $review->id)
            ->exists());

        try {
            app(TalentCalibrationService::class)->finalize($final->refresh(), $hr);
            $this->fail('Finalizing a final review must refuse.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('form', $e->errors());
        }
    }

    public function test_finalize_outside_calibration_refuses(): void
    {
        $review = $this->reviewInStage(PerformanceCycleStage::Completed);

        try {
            app(TalentCalibrationService::class)->finalize($review, $this->hr());
            $this->fail('Finalizing outside the calibration stage must refuse.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('stage', $e->errors());
        }
    }

    // ------------------------------------------------------------ helpers

    private function hr(): User
    {
        $this->connectTenant('acme');

        return User::create([
            'name' => 'Talent Calibrator', 'email' => 'talent.calibrator@flowsync.test', 'password' => 'password',
        ]);
    }

    /**
     * @param  array<string, mixed>  $reviewAttributes
     */
    private function reviewInStage(PerformanceCycleStage $stage, array $reviewAttributes = []): ReviewSummary
    {
        $this->connectTenant('acme');

        static $sequence = 0;
        $sequence++;

        $employee = Employee::create([
            'employee_code' => "EMP-TC-{$sequence}", 'name' => "Talent Case {$sequence}",
            'status' => EmployeeStatus::Active,
        ]);

        $cycle = PerformanceCycle::create([
            'name' => "Talent Cycle {$sequence}",
            'slug' => "talent-cycle-{$sequence}",
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'stage' => $stage,
        ]);

        return ReviewSummary::create([
            'cycle_id' => $cycle->id,
            'employee_id' => $employee->id,
            ...$reviewAttributes,
        ]);
    }
}
