<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * Phase 24.2 — the calibration room over HTTP.
 *
 * The grid, the two writes, the distribution and the 360 summary all sit
 * behind `hrms.talent` plus the calibrator policy (`hrms.talent.manage`
 * alone — a performance manager without it gets a 403, not a room).
 * Summaries carry numbers only: peer names and bodies never aggregate.
 */
class HrmsTalentApiTest extends TestCase
{
    use IsolatesDatabase;

    public function test_talent_grid_gates_on_the_hrms_talent_module(): void
    {
        $cycleId = $this->calibrationReadyCycle();
        $this->assignProToAcme();
        $this->setAcmeModules(['hrms.core', 'hrms.performance']);
        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');

        $this->getJson("/api/hrms/talent/cycles/{$cycleId}/reviews")
            ->assertForbidden()
            ->assertHeader('X-Module-Denied', 'hrms.talent');

        $this->setAcmeModules(['hrms.core', 'hrms.performance', 'hrms.talent']);

        $this->getJson("/api/hrms/talent/cycles/{$cycleId}/reviews")
            ->assertOk()
            ->assertJsonPath('reviews', []);
    }

    public function test_calibrate_needs_talent_manage_not_performance_manage(): void
    {
        [$manager, $report] = $this->reportingLine();
        $cycleId = $this->calibrationReadyCycle($report);

        // A performance manager files reviews and walks cycles, but the
        // calibration room is talent's: same hands, 403.
        $this->actAs($this->userWith(['hrms.view', 'hrms.performance.view', 'hrms.performance.manage']));

        $review = $this->postJson("/api/hrms/performance/cycles/{$cycleId}/reviews", [
            'employee_id' => $report->id,
            'manager_rating' => 3,
        ])->assertCreated()->json('review');

        $this->postJson("/api/hrms/talent/reviews/{$review['id']}/calibrate", ['rating' => 4])
            ->assertForbidden();

        $this->actAs($this->userWith(['hrms.view', 'hrms.talent.manage']));

        $this->postJson("/api/hrms/talent/reviews/{$review['id']}/calibrate", ['rating' => 4])
            ->assertOk()
            ->assertJsonPath('review.status', 'calibrating')
            ->assertJsonPath('review.calibrated_rating', 4);
    }

    public function test_calibrate_validates_and_finalize_seals(): void
    {
        [$manager, $report] = $this->reportingLine();
        $cycleId = $this->calibrationReadyCycle($report);
        $this->actAs($this->userWith(['hrms.view', 'hrms.performance.manage', 'hrms.talent.manage']));

        $review = $this->postJson("/api/hrms/performance/cycles/{$cycleId}/reviews", [
            'employee_id' => $report->id,
            'manager_rating' => 3,
        ])->assertCreated()->json('review');

        $this->postJson("/api/hrms/talent/reviews/{$review['id']}/calibrate", ['rating' => 6])
            ->assertUnprocessable();

        $this->postJson("/api/hrms/talent/reviews/{$review['id']}/finalize", [])
            ->assertOk()
            ->assertJsonPath('review.status', 'final');
    }

    public function test_cross_tenant_review_is_404(): void
    {
        [$manager, $report] = $this->reportingLine();
        $cycleId = $this->calibrationReadyCycle($report);
        $this->actAs($this->userWith(['hrms.view', 'hrms.performance.manage', 'hrms.talent.manage']));

        $review = $this->postJson("/api/hrms/performance/cycles/{$cycleId}/reviews", [
            'employee_id' => $report->id,
        ])->assertCreated()->json('review');

        // Globex holds no subscription, so no module gate can answer first:
        // the binding itself must 404, never leak across the DB boundary.
        $this->postJson('/api/auth/login', ['email' => 'owner@globex.test', 'password' => 'password'])->assertOk();

        $this->postJson("/api/hrms/talent/reviews/{$review['id']}/calibrate", ['rating' => 4])
            ->assertNotFound();
    }

    public function test_feedback_summary_aggregates_without_names_or_words(): void
    {
        [$manager, $report, $peer] = $this->trio();
        $this->actAs($this->userWith(['hrms.view', 'hrms.performance.manage', 'hrms.talent.manage']));

        $cycle = $this->postJson('/api/hrms/performance/cycles', [
            'name' => 'Talent 360', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31',
        ])->assertCreated()->json('cycle');

        foreach ([$report, $peer] as $person) {
            $this->postJson("/api/hrms/performance/cycles/{$cycle['id']}/goals", [
                'employee_id' => $person->id, 'title' => 'Cycle goal.',
                'metric_type' => 'manual', 'weight' => '100',
            ])->assertCreated();
        }

        $this->walkToCalibration($cycle['id']);

        $asks = $this->getJson("/api/hrms/performance/cycles/{$cycle['id']}/feedback-requests")->assertOk()->json('feedback_requests');
        $peerAsk = collect($asks)->firstWhere(fn ($ask) => (int) $ask['from']['id'] === $peer->id && (int) $ask['to']['id'] === $report->id);

        $this->actAs($this->userWith(['hrms.view'], $peer));
        $this->postJson("/api/hrms/performance/feedback-requests/{$peerAsk['id']}/respond", [
            'action' => 'submit', 'rating' => 4, 'body' => 'Solid quarter.',
        ])->assertOk();

        $this->actAs($this->userWith(['hrms.view', 'hrms.talent.manage']));

        $response = $this->getJson("/api/hrms/talent/cycles/{$cycle['id']}/feedback-summary")->assertOk();
        $summaries = $response->json('feedback_summary');
        $forReport = collect($summaries)->firstWhere('employee_id', $report->id);

        $this->assertSame(1, $forReport['by_relation']['peer']['count']);
        $this->assertEquals(4, $forReport['by_relation']['peer']['average_rating']);

        // Numbers travel; reviewers do not. (The peer legitimately appears
        // as a reviewee in their own summary row — what must never appear
        // is anyone as a reviewer: no `from`, no `body`, no quoted words.)
        $this->assertStringNotContainsString('"from"', $response->getContent());
        $this->assertStringNotContainsString('"body"', $response->getContent());
        $this->assertStringNotContainsString('Solid quarter.', $response->getContent());
    }

    public function test_distribution_counts_without_averages_or_names(): void
    {
        [$manager, $report] = $this->reportingLine();
        $cycleId = $this->calibrationReadyCycle($report);
        $this->actAs($this->userWith(['hrms.view', 'hrms.performance.manage', 'hrms.talent.manage']));

        $first = $this->postJson("/api/hrms/performance/cycles/{$cycleId}/reviews", [
            'employee_id' => $report->id, 'manager_rating' => 4,
        ])->assertCreated()->json('review');

        $peer = $this->makeEmployee();
        $second = $this->postJson("/api/hrms/performance/cycles/{$cycleId}/reviews", [
            'employee_id' => $peer->id, 'manager_rating' => 5,
        ])->assertCreated()->json('review');

        $this->postJson("/api/hrms/talent/reviews/{$second['id']}/calibrate", ['rating' => 2])->assertOk();

        $response = $this->getJson("/api/hrms/talent/cycles/{$cycleId}/distribution")->assertOk();

        // The calibrated 5 now counts as a 2; the untouched 4 stays a 4.
        $response->assertJsonPath('distribution.by_rating.2', 1)
            ->assertJsonPath('distribution.by_rating.4', 1)
            ->assertJsonPath('distribution.unrated', 0)
            ->assertJsonPath('distribution.total', 2);

        $this->assertStringNotContainsString('average', $response->getContent());
        $this->assertStringNotContainsString($report->name, $response->getContent());
    }

    // ------------------------------------------------------------ helpers

    /**
     * A cycle walked to calibration with a goaled report, on a tenant
     * holding the performance and talent modules.
     */
    private function calibrationReadyCycle(?Employee $report = null): int
    {
        $this->assignProToAcme();
        $this->setAcmeModules(['hrms.core', 'hrms.performance', 'hrms.talent']);
        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');

        $cycle = $this->postJson('/api/hrms/performance/cycles', [
            'name' => 'Talent Calibration', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31',
        ])->assertCreated()->json('cycle');

        if ($report !== null) {
            $this->postJson("/api/hrms/performance/cycles/{$cycle['id']}/goals", [
                'employee_id' => $report->id, 'title' => 'Cycle goal.',
                'metric_type' => 'manual', 'weight' => '100',
            ])->assertCreated();
        }

        $this->walkToCalibration($cycle['id']);

        return (int) $cycle['id'];
    }

    private function walkToCalibration(int $cycleId): void
    {
        foreach (['open-check-in', 'open-self-review', 'open-manager-review', 'open-calibration'] as $step) {
            $this->postJson("/api/hrms/performance/cycles/{$cycleId}/{$step}", [])->assertOk();
        }
    }

    private function login(string $email): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
    }

    /**
     * @return array{Employee, Employee} Manager and report, each with `->user`.
     */
    private function reportingLine(): array
    {
        $this->connectTenant('acme');

        $managerUser = User::create([
            'name' => 'Talent Api Manager', 'email' => 'talent.api.manager@flowsync.test', 'password' => 'password',
        ]);
        $manager = Employee::create([
            'employee_code' => 'EMP-TA-MGR', 'name' => 'Talent Api Manager',
            'status' => EmployeeStatus::Active, 'user_id' => $managerUser->id,
        ]);
        $manager->user = $managerUser;

        $reportUser = User::create([
            'name' => 'Talent Api Report', 'email' => 'talent.api.report@flowsync.test', 'password' => 'password',
        ]);
        $report = Employee::create([
            'employee_code' => 'EMP-TA-REP', 'name' => 'Talent Api Report',
            'status' => EmployeeStatus::Active, 'user_id' => $reportUser->id, 'manager_id' => $manager->id,
        ]);
        $report->user = $reportUser;

        return [$manager, $report];
    }

    /**
     * @return array{Employee, Employee, Employee}
     */
    private function trio(): array
    {
        [$manager, $report] = $this->reportingLine();

        $peerUser = User::create([
            'name' => 'Talent Api Peer', 'email' => 'talent.api.peer@flowsync.test', 'password' => 'password',
        ]);
        $peer = Employee::create([
            'employee_code' => 'EMP-TA-PEER', 'name' => 'Talent Api Peer',
            'status' => EmployeeStatus::Active, 'user_id' => $peerUser->id, 'manager_id' => $manager->id,
        ]);
        $peer->user = $peerUser;

        return [$manager, $report, $peer];
    }

    private function makeEmployee(): Employee
    {
        $this->connectTenant('acme');

        return Employee::create([
            'employee_code' => 'EMP-TA-'.strtoupper(substr(md5((string) mt_rand()), 0, 6)),
            'name' => 'Talent Api Extra',
            'status' => EmployeeStatus::Active,
        ]);
    }

    private function userWith(array $permissionSlugs, ?Employee $employee = null): User
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        if ($employee !== null && $employee->user_id !== null) {
            $user = User::findOrFail($employee->user_id);
        } else {
            $user = User::create([
                'name' => "Talent Api User {$sequence}",
                'email' => "talent.api.user.{$sequence}@flowsync.test",
                'password' => 'password',
            ]);
        }

        $role = Role::create([
            'name' => "Talent Api Role {$sequence}",
            'slug' => "talent-api-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }

    private function actAs(User $user): void
    {
        $this->connectTenant('acme');
        $this->actingAs($user)->withSession(['login.tenant_id' => $this->acme()->id]);
    }

    private function assignProToAcme(): void
    {
        app(SubscriptionService::class)->assign($this->acme(), $this->proPlan());
    }

    private function setAcmeModules(array $modules): void
    {
        $plan = $this->proPlan();
        $plan->update(['limits' => array_merge($plan->limits, ['modules' => $modules])]);
    }

    private function proPlan(): SubscriptionPlan
    {
        return SubscriptionPlan::where('slug', 'pro')->firstOrFail();
    }
}
