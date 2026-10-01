<?php

namespace App\Services\Hrms\Performance;

use App\Enums\Hrms\FeedbackRelation;
use App\Enums\Hrms\GoalStatus;
use App\Enums\Hrms\PerformanceCycleStage;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Performance\FeedbackRequest;
use App\Models\Hrms\Performance\PerformanceCycle;
use App\Models\Hrms\Shared\HrmsSetting;
use App\Models\User;
use App\Services\Hrms\Employee\ReportingLine;
use App\Services\Hrms\Org\OrgNaming;
use App\Services\HrmsAuditLogger;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Performance/HRMS — the cycle state machine.
 *
 * Six public methods, one per legal move: create (in goal setting),
 * forward through check-in, self review, manager review (which generates
 * feedback), calibration, and complete (which notifies and locks). Every
 * move refuses out-of-order calls by name, audits identifiers and states,
 * and never writes a rating — filing reviews belongs to P12.4, and the
 * machine only opens the rooms.
 */
class PerformanceCycleService
{
    public function __construct(
        private readonly OrgNaming $naming,
        private readonly ReportingLine $reporting,
        private readonly HrmsAuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCycle(array $data, ?User $actor = null): PerformanceCycle
    {
        $cycle = PerformanceCycle::create([
            ...$data,
            'slug' => $this->naming->uniqueSlug(PerformanceCycle::class, (string) $data['name']),
            'stage' => PerformanceCycleStage::GoalSetting,
            'created_by' => $actor?->id,
        ]);

        $this->audit->log($cycle, 'performance.cycle_created', null, $this->snapshot($cycle), $actor);

        return $cycle->refresh();
    }

    /**
     * Leave goal setting for check-ins: every goaled employee's
     * non-cancelled weights must total 100 ± 0.01 first. Employees without
     * goals are not validated — a service account is not a reviewee, and
     * forcing one would invent commitments for people outside the cycle.
     *
     * @throws ValidationException on a bad weight total
     */
    public function openCheckIn(PerformanceCycle $cycle, ?User $actor = null): PerformanceCycle
    {
        $this->requireStage($cycle, PerformanceCycleStage::GoalSetting, 'check-ins');
        $this->validateWeights($cycle);

        $moved = $this->transition($cycle, PerformanceCycleStage::CheckIn, 'performance.cycle_check_in_opened', $actor);
        $this->notifications->performanceCycleOpened($moved, $actor);

        return $moved;
    }

    public function openSelfReview(PerformanceCycle $cycle, ?User $actor = null): PerformanceCycle
    {
        $this->requireStage($cycle, PerformanceCycleStage::CheckIn, 'self review');

        return $this->transition($cycle, PerformanceCycleStage::SelfReview, 'performance.cycle_self_review_opened', $actor);
    }

    /**
     * Open manager review and generate its feedback: each reviewee gets
     * their manager plus N peers (the settings count), deterministic by id
     * so a re-run names the same names instead of sampling new ones.
     */
    public function openManagerReview(PerformanceCycle $cycle, ?User $actor = null): PerformanceCycle
    {
        $this->requireStage($cycle, PerformanceCycleStage::SelfReview, 'manager review');

        return DB::transaction(function () use ($cycle, $actor): PerformanceCycle {
            $cycle->update(['stage' => PerformanceCycleStage::ManagerReview]);

            $generated = $this->generateFeedback($cycle, $actor);

            $this->audit->log($cycle->refresh(), 'performance.cycle_manager_review_opened', null, [
                ...$this->snapshot($cycle->refresh()),
                'feedback_requests' => $generated,
            ], $actor);

            return $cycle->refresh();
        });
    }

    public function openCalibration(PerformanceCycle $cycle, ?User $actor = null): PerformanceCycle
    {
        $this->requireStage($cycle, PerformanceCycleStage::ManagerReview, 'calibration');

        return $this->transition($cycle, PerformanceCycleStage::Calibration, 'performance.cycle_calibration_opened', $actor);
    }

    /**
     * Seal the cycle and tell every participant: completed locks the rooms
     * (goal edits, check-ins and reviews refuse a sealed cycle in P12.4),
     * and the notification is the participants' receipt.
     */
    public function complete(PerformanceCycle $cycle, ?User $actor = null): PerformanceCycle
    {
        $this->requireStage($cycle, PerformanceCycleStage::Calibration, 'completion');

        return DB::transaction(function () use ($cycle, $actor): PerformanceCycle {
            $cycle->update(['stage' => PerformanceCycleStage::Completed]);

            $notified = $this->notifications->performanceCycleCompleted($cycle->refresh(), $actor);

            $this->audit->log($cycle->refresh(), 'performance.cycle_completed', null, [
                ...$this->snapshot($cycle->refresh()),
                'notified' => count($notified),
            ], $actor);

            return $cycle->refresh();
        });
    }

    /**
     * @throws ValidationException on any other stage
     */
    private function requireStage(PerformanceCycle $cycle, PerformanceCycleStage $expected, string $target): void
    {
        if ($cycle->stage !== $expected) {
            throw ValidationException::withMessages([
                'stage' => "That cycle is {$cycle->stage->value}, not {$expected->value} — {$target} opens from {$expected->value}.",
            ]);
        }
    }

    private function transition(
        PerformanceCycle $cycle,
        PerformanceCycleStage $to,
        string $action,
        ?User $actor,
    ): PerformanceCycle {
        return DB::transaction(function () use ($cycle, $to, $action, $actor): PerformanceCycle {
            $before = $this->snapshot($cycle);
            $cycle->update(['stage' => $to]);

            $this->audit->log($cycle->refresh(), $action, $before, $this->snapshot($cycle->refresh()), $actor);

            return $cycle->refresh();
        });
    }

    /**
     * @throws ValidationException naming the first unbalanced employee
     */
    private function validateWeights(PerformanceCycle $cycle): void
    {
        $employeeIds = $cycle->goals()
            ->where('status', '!=', GoalStatus::Cancelled)
            ->distinct()
            ->pluck('employee_id');

        foreach ($employeeIds as $employeeId) {
            $total = '0';

            foreach ($cycle->goals()->where('employee_id', $employeeId)->where('status', '!=', GoalStatus::Cancelled)->pluck('weight') as $weight) {
                $total = bcadd($total, (string) $weight, 2);
            }

            if (bccomp($total, '99.99', 2) < 0 || bccomp($total, '100.01', 2) > 0) {
                $employee = Employee::find($employeeId);

                throw ValidationException::withMessages([
                    'weights' => ($employee?->employee_code ?? "Employee {$employeeId}")."’s goals weigh {$total}, not 100.",
                ]);
            }
        }
    }

    /**
     * One manager ask plus N peer asks per reviewee — everyone with a goal
     * in the cycle. No manager means no manager ask (the row would name
     * nobody); peers exclude the reviewee and their manager; existing asks
     * are kept, so re-running after a fix-up adds only what is missing.
     */
    private function generateFeedback(PerformanceCycle $cycle, ?User $actor): int
    {
        $peerCount = (int) (HrmsSetting::current()->setting('performance.feedback_peer_count', 2) ?? 2);
        $generated = 0;

        $reviewees = Employee::query()->whereIn(
            'id',
            $cycle->goals()->distinct()->pluck('employee_id'),
        )->orderBy('id')->get();

        foreach ($reviewees as $reviewee) {
            $manager = $this->reporting->managerOf($reviewee);

            if ($manager !== null) {
                $generated += $this->askOnce($cycle, $manager->id, $reviewee->id, FeedbackRelation::Manager, $actor) ? 1 : 0;
            }

            $peers = Employee::query()->active()
                ->where('id', '!=', $reviewee->id)
                ->when($manager !== null, fn ($query) => $query->where('id', '!=', $manager->id))
                ->orderBy('id')
                ->limit(max(0, $peerCount))
                ->pluck('id');

            foreach ($peers as $peerId) {
                $generated += $this->askOnce($cycle, (int) $peerId, $reviewee->id, FeedbackRelation::Peer, $actor) ? 1 : 0;
            }
        }

        return $generated;
    }

    private function askOnce(
        PerformanceCycle $cycle,
        int $from,
        int $to,
        FeedbackRelation $relation,
        ?User $actor,
    ): bool {
        $exists = FeedbackRequest::query()->where([
            'cycle_id' => $cycle->id,
            'from_employee_id' => $from,
            'to_employee_id' => $to,
        ])->exists();

        if ($exists) {
            return false;
        }

        FeedbackRequest::create([
            'cycle_id' => $cycle->id,
            'from_employee_id' => $from,
            'to_employee_id' => $to,
            'relation' => $relation,
            'status' => 'pending',
            'due_date' => $cycle->period_end->toDateString(),
            'created_by' => $actor?->id,
        ]);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(PerformanceCycle $cycle): array
    {
        return [
            'slug' => $cycle->slug,
            'stage' => $cycle->stage->value,
        ];
    }
}
