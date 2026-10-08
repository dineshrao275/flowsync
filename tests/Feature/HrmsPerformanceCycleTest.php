<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Enums\Hrms\FeedbackRelation;
use App\Enums\Hrms\GoalMetricType;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Performance\FeedbackRequest;
use App\Models\Hrms\Performance\PerformanceCycle;
use App\Models\Hrms\Performance\PerformanceGoal;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Hrms\Performance\PerformanceCycleService;
use Illuminate\Validation\ValidationException;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P12.3 — the cycle state machine.
 *
 * Forward only, each room auditing its opening: goal setting validates
 * weights to 100 ± 0.01 per goaled employee before check-ins open,
 * manager review generates one manager ask plus N deterministic peer
 * asks, and completion notifies the participants and locks the past.
 */
class HrmsPerformanceCycleTest extends TestCase
{
    use IsolatesDatabase;

    public function test_a_cycle_walks_forward_and_refuses_backwards(): void
    {
        $service = app(PerformanceCycleService::class);
        $cycle = $service->createCycle($this->cycleData());

        $this->assertSame('goal_setting', $cycle->stage->value);
        $this->assertSame('h1-2026', $cycle->slug);

        $service->openCheckIn($cycle->refresh());
        $this->assertSame('check_in', $cycle->refresh()->stage->value);

        // Out of order is refused by name.
        try {
            $service->openCalibration($cycle->refresh());
            $this->fail('A check-in cycle calibrated.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('check_in', json_encode($exception->errors()));
        }

        $service->openSelfReview($cycle->refresh());
        $service->openManagerReview($cycle->refresh());
        $service->openCalibration($cycle->refresh());
        $service->complete($cycle->refresh());

        $this->assertSame('completed', $cycle->refresh()->stage->value);

        try {
            $service->openCheckIn($cycle->refresh());
            $this->fail('A completed cycle reopened.');
        } catch (ValidationException) {
        }
    }

    public function test_weights_must_total_100_per_goaled_employee(): void
    {
        $service = app(PerformanceCycleService::class);
        [$manager, $report] = $this->reportingLine();
        $cycle = $service->createCycle($this->cycleData());

        $this->goal($cycle, $report, '60');
        $this->goal($cycle, $report, '30');

        try {
            $service->openCheckIn($cycle->refresh());
            $this->fail('Ninety percent opened check-ins.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('90.00', json_encode($exception->errors()));
        }

        $this->goal($cycle, $report, '10');

        // The ungoaled manager is not validated; the balanced report is.
        $service->openCheckIn($cycle->refresh());
        $this->assertSame('check_in', $cycle->refresh()->stage->value);
    }

    public function test_manager_review_generates_one_manager_ask_plus_peers(): void
    {
        $service = app(PerformanceCycleService::class);
        [$manager, $report, $peer] = $this->trio();
        $cycle = $service->createCycle($this->cycleData());

        $this->goal($cycle, $manager, '100');
        $this->goal($cycle, $report, '100');
        $this->goal($cycle, $peer, '100');

        $service->openCheckIn($cycle->refresh());
        $service->openSelfReview($cycle->refresh());
        $service->openManagerReview($cycle->refresh());

        $asks = FeedbackRequest::query()->where('cycle_id', $cycle->id)->get();

        // The report: one manager ask plus the single eligible peer.
        $this->assertSame(1, $asks->where('to_employee_id', $report->id)->where('relation', FeedbackRelation::Manager)->count());
        $this->assertSame(1, $asks->where('to_employee_id', $report->id)->where('relation', FeedbackRelation::Peer)->count());

        // The manager has no manager: peer asks only, never a self ask.
        $this->assertSame(0, $asks->where('to_employee_id', $manager->id)->where('relation', FeedbackRelation::Manager)->count());
        $this->assertSame(2, $asks->where('to_employee_id', $manager->id)->where('relation', FeedbackRelation::Peer)->count());
        $this->assertFalse($asks->contains(fn ($ask) => (int) $ask->from_employee_id === (int) $ask->to_employee_id));

        // Six asks, deterministic: manager + peer per reviewee, two peers
        // for the managerless manager.
        $this->assertCount(6, $asks);
    }

    public function test_completion_notifies_participants_and_only_them(): void
    {
        $service = app(PerformanceCycleService::class);
        [$manager, $report] = $this->reportingLine();
        $cycle = $service->createCycle($this->cycleData());
        $this->goal($cycle, $report, '100');

        $service->openCheckIn($cycle->refresh());
        $service->openSelfReview($cycle->refresh());
        $service->openManagerReview($cycle->refresh());
        $service->openCalibration($cycle->refresh());
        $service->complete($cycle->refresh(), $manager->user);

        $this->assertTrue(UserNotification::query()
            ->where('user_id', $report->user->id)
            ->where('type', 'hrms.performance.cycle_completed')
            ->exists());
        $this->assertFalse(UserNotification::query()
            ->where('user_id', $manager->user->id)
            ->where('type', 'hrms.performance.cycle_completed')
            ->exists());
    }

    // ------------------------------------------------------------ helpers

    /**
     * @return array<string, mixed>
     */
    private function cycleData(): array
    {
        return [
            'name' => 'H1 2026',
            'period_start' => '2026-01-01',
            'period_end' => '2026-06-30',
        ];
    }

    private function goal(PerformanceCycle $cycle, Employee $employee, string $weight): PerformanceGoal
    {
        $this->connectTenant('acme');

        return PerformanceGoal::create([
            'cycle_id' => $cycle->id,
            'employee_id' => $employee->id,
            'title' => 'Cycle goal.',
            'metric_type' => GoalMetricType::Manual,
            'weight' => $weight,
            'status' => 'active',
        ]);
    }

    /**
     * @return array{Employee, Employee} Manager and report, each with `->user`.
     */
    private function reportingLine(): array
    {
        $this->connectTenant('acme');

        $managerUser = User::create([
            'name' => 'Cycle Manager', 'email' => 'cycle.manager@flowsync.test', 'password' => 'password',
        ]);
        $manager = Employee::create([
            'employee_code' => 'EMP-CYC-MGR', 'name' => 'Cycle Manager',
            'status' => EmployeeStatus::Active, 'user_id' => $managerUser->id,
        ]);
        $manager->user = $managerUser;

        $reportUser = User::create([
            'name' => 'Cycle Report', 'email' => 'cycle.report@flowsync.test', 'password' => 'password',
        ]);
        $report = Employee::create([
            'employee_code' => 'EMP-CYC-REP', 'name' => 'Cycle Report',
            'status' => EmployeeStatus::Active, 'user_id' => $reportUser->id, 'manager_id' => $manager->id,
        ]);
        $report->user = $reportUser;

        return [$manager, $report];
    }

    /**
     * @return array{Employee, Employee, Employee} Manager, report, and peer.
     */
    private function trio(): array
    {
        [$manager, $report] = $this->reportingLine();

        $peerUser = User::create([
            'name' => 'Cycle Peer', 'email' => 'cycle.peer@flowsync.test', 'password' => 'password',
        ]);
        $peer = Employee::create([
            'employee_code' => 'EMP-CYC-PEER', 'name' => 'Cycle Peer',
            'status' => EmployeeStatus::Active, 'user_id' => $peerUser->id, 'manager_id' => $manager->id,
        ]);
        $peer->user = $peerUser;

        return [$manager, $report, $peer];
    }
}
