<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P12.4b — feedback, reviews and evidence over HTTP.
 *
 * Asks answer through the reviewer alone with anonymity tiers on read
 * (peer cycles aggregate for the reviewee, managers read everything);
 * reviews file through the manager with the owner's half withheld until
 * shared; acknowledging notifies the employee; and sealed cycles refuse
 * new writes while evidence reads never recompute.
 */
class HrmsPerformanceApiTest extends TestCase
{
    use IsolatesDatabase;

    public function test_a_cycle_goal_and_note_walk_end_to_end(): void
    {
        [$manager, $report] = $this->reportingLine();
        $this->actAs($this->userWith(['hrms.view', 'hrms.performance.view', 'hrms.performance.manage']));

        $cycle = $this->postJson('/api/hrms/performance/cycles', [
            'name' => 'H1 2026',
            'period_start' => '2026-01-01',
            'period_end' => '2026-06-30',
        ])->assertCreated()->json('cycle');

        $goal = $this->postJson("/api/hrms/performance/cycles/{$cycle['id']}/goals", [
            'employee_id' => $report->id,
            'title' => 'Ship the migration.',
            'metric_type' => 'manual',
            'weight' => '100',
        ])->assertCreated()->json('goal');

        // Self edits while draft; manage locks it active; self then 403s.
        $this->actAs($this->userWith(['hrms.view'], $report));
        $this->putJson("/api/hrms/performance/goals/{$goal['id']}", ['title' => 'Ship it.'])->assertOk();

        $this->actAs($this->userWith(['hrms.view', 'hrms.performance.view', 'hrms.performance.manage']));
        $this->putJson("/api/hrms/performance/goals/{$goal['id']}", ['status' => 'active'])->assertOk();

        $this->actAs($this->userWith(['hrms.view'], $report));
        $this->putJson("/api/hrms/performance/goals/{$goal['id']}", ['title' => 'Ship it twice.'])->assertForbidden();

        // Notes append without an update endpoint.
        $this->postJson("/api/hrms/performance/cycles/{$cycle['id']}/check-ins", [
            'employee_id' => $report->id,
            'body' => 'Halfway there.',
            'mood' => 'good',
        ])->assertCreated();
    }

    public function test_peer_anonymity_aggregates_for_the_reviewee(): void
    {
        [$manager, $report, $peer] = $this->trio();
        $this->actAs($this->userWith(['hrms.view', 'hrms.performance.view', 'hrms.performance.manage']));

        $cycle = $this->postJson('/api/hrms/performance/cycles', [
            'name' => 'H2 2026',
            'period_start' => '2026-07-01',
            'period_end' => '2026-12-31',
            'anonymity' => 'peer',
        ])->assertCreated()->json('cycle');

        foreach ([$report, $peer] as $person) {
            $this->postJson("/api/hrms/performance/cycles/{$cycle['id']}/goals", [
                'employee_id' => $person->id,
                'title' => 'Cycle goal.',
                'metric_type' => 'manual',
                'weight' => '100',
            ])->assertCreated();
        }

        $this->walkToManagerReview($cycle['id']);

        $asks = $this->getJson("/api/hrms/performance/cycles/{$cycle['id']}/feedback-requests")->assertOk()->json('feedback_requests');
        $peerAsk = collect($asks)->firstWhere(fn ($ask) => (int) $ask['from']['id'] === $peer->id && (int) $ask['to']['id'] === $report->id);

        // The requested reviewer answers; a bystander cannot.
        $this->actAs($this->userWith(['hrms.view'], $peer));
        $this->postJson("/api/hrms/performance/feedback-requests/{$peerAsk['id']}/respond", [
            'action' => 'submit', 'rating' => 4, 'body' => 'Solid quarter.',
        ])->assertOk();

        $this->actAs($this->userWith(['hrms.view'], $report));
        $this->postJson("/api/hrms/performance/feedback-requests/{$peerAsk['id']}/respond", [
            'action' => 'submit', 'rating' => 5, 'body' => 'Mine.',
        ])->assertForbidden();

        // The reviewee reads aggregates, never the peer's words.
        $asReviewee = $this->getJson("/api/hrms/performance/feedback-requests/{$peerAsk['id']}")->assertOk()->json('feedback_request');
        $this->assertSame(1, $asReviewee['responses']['count']);
        $this->assertSame([], $asReviewee['responses']['rows']);
        $this->assertEquals(4, $asReviewee['responses']['average_rating']);

        // The manager reads everything, including the words.
        $this->actAs($this->userWith(['hrms.view'], $manager));
        $asManager = $this->getJson("/api/hrms/performance/feedback-requests/{$peerAsk['id']}")->assertOk()->json('feedback_request');
        $this->assertSame('Solid quarter.', $asManager['responses']['rows'][0]['body']);
        $this->assertSame($peer->id, $asManager['responses']['rows'][0]['from']['id']);
    }

    public function test_reviews_share_on_release_and_acknowledge_notifies(): void
    {
        [$manager, $report] = $this->reportingLine();
        $this->actAs($this->userWith(['hrms.view', 'hrms.performance.view', 'hrms.performance.manage']));

        $cycle = $this->postJson('/api/hrms/performance/cycles', [
            'name' => 'H3 2026',
            'period_start' => '2026-07-01',
            'period_end' => '2026-12-31',
        ])->assertCreated()->json('cycle');

        $review = $this->postJson("/api/hrms/performance/cycles/{$cycle['id']}/reviews", [
            'employee_id' => $report->id,
            'manager_rating' => 4,
            'manager_comments' => 'Needs polish.',
            'strengths' => 'Ships fast.',
        ])->assertCreated()->json('review');

        // Hidden: the owner reads their own words, not the manager's half.
        $this->actAs($this->userWith(['hrms.view'], $report));
        $hidden = $this->getJson("/api/hrms/performance/reviews/{$review['id']}")->assertOk()->json('review');
        $this->assertSame('Ships fast.', $hidden['strengths']);
        $this->assertNull($hidden['manager_rating']);
        $this->assertNull($hidden['manager_comments']);

        // A peer never sees another person's rating.
        $stranger = $this->makeEmployee(withUser: true);
        $this->actAs($this->userWith(['hrms.view'], $stranger));
        $this->getJson("/api/hrms/performance/reviews/{$review['id']}")->assertForbidden();

        // Released: the manager's half shows, and acknowledging notifies.
        $this->actAs($this->userWith(['hrms.view', 'hrms.performance.view', 'hrms.performance.manage']));
        $this->putJson("/api/hrms/performance/reviews/{$review['id']}", ['visibility_to_employee' => 'shared'])->assertOk();

        $this->actAs($this->userWith(['hrms.view'], $manager));
        $shared = $this->getJson("/api/hrms/performance/reviews/{$review['id']}")->assertOk()->json('review');
        $this->assertSame(4, $shared['manager_rating']);

        $this->postJson("/api/hrms/performance/reviews/{$review['id']}/acknowledge", [])->assertOk();

        $this->assertTrue(UserNotification::query()
            ->where('user_id', $report->user->id)
            ->where('type', 'hrms.performance.review_acknowledged')
            ->exists());
    }

    public function test_a_sealed_cycle_refuses_writes_but_evidence_reads(): void
    {
        [$manager, $report] = $this->reportingLine();
        $this->actAs($this->userWith(['hrms.view', 'hrms.performance.view', 'hrms.performance.manage']));

        $cycle = $this->postJson('/api/hrms/performance/cycles', [
            'name' => 'H4 2026',
            'period_start' => '2026-07-01',
            'period_end' => '2026-12-31',
        ])->assertCreated()->json('cycle');

        $this->postJson("/api/hrms/performance/cycles/{$cycle['id']}/goals", [
            'employee_id' => $report->id,
            'title' => 'Cycle goal.',
            'metric_type' => 'manual',
            'weight' => '100',
        ])->assertCreated();

        foreach (['open-check-in', 'open-self-review', 'open-manager-review', 'open-calibration', 'complete'] as $step) {
            $this->postJson("/api/hrms/performance/cycles/{$cycle['id']}/{$step}", [])->assertOk();
        }

        // Sealed: no new goals, notes, or reviews.
        $this->postJson("/api/hrms/performance/cycles/{$cycle['id']}/goals", [
            'employee_id' => $report->id,
            'title' => 'Late goal.',
        ])->assertStatus(422);
        $this->postJson("/api/hrms/performance/cycles/{$cycle['id']}/check-ins", [
            'employee_id' => $report->id,
            'body' => 'Late note.',
        ])->assertStatus(422);

        // But the evidence photograph still reads — and refreshes.
        $this->getJson("/api/hrms/performance/cycles/{$cycle['id']}/evidence")->assertOk();
        $this->postJson("/api/hrms/performance/cycles/{$cycle['id']}/evidence", [])
            ->assertOk()->assertJsonPath('summary.goals', 1);
    }

    // ------------------------------------------------------------ helpers

    private function walkToManagerReview(int $cycleId): void
    {
        foreach (['open-check-in', 'open-self-review', 'open-manager-review'] as $step) {
            $this->postJson("/api/hrms/performance/cycles/{$cycleId}/{$step}", [])->assertOk();
        }
    }

    /**
     * @return array{Employee, Employee} Manager and report, each with `->user`.
     */
    private function reportingLine(): array
    {
        $this->connectTenant('acme');

        $managerUser = User::create([
            'name' => 'Perf Api Manager', 'email' => 'perf.api.manager@flowsync.test', 'password' => 'password',
        ]);
        $manager = Employee::create([
            'employee_code' => 'EMP-PA-MGR', 'name' => 'Perf Api Manager',
            'status' => EmployeeStatus::Active, 'user_id' => $managerUser->id,
        ]);
        $manager->user = $managerUser;

        $reportUser = User::create([
            'name' => 'Perf Api Report', 'email' => 'perf.api.report@flowsync.test', 'password' => 'password',
        ]);
        $report = Employee::create([
            'employee_code' => 'EMP-PA-REP', 'name' => 'Perf Api Report',
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
            'name' => 'Perf Api Peer', 'email' => 'perf.api.peer@flowsync.test', 'password' => 'password',
        ]);
        $peer = Employee::create([
            'employee_code' => 'EMP-PA-PEER', 'name' => 'Perf Api Peer',
            'status' => EmployeeStatus::Active, 'user_id' => $peerUser->id, 'manager_id' => $manager->id,
        ]);
        $peer->user = $peerUser;

        return [$manager, $report, $peer];
    }

    private function makeEmployee(bool $withUser = false): Employee
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        $userId = $withUser ? User::create([
            'name' => "Perf Api Owner {$sequence}",
            'email' => "perf.api.owner.{$sequence}@flowsync.test",
            'password' => 'password',
        ])->id : null;

        return Employee::create([
            'employee_code' => 'EMP-PA-'.$sequence,
            'name' => "Perf Api Employee {$sequence}",
            'status' => EmployeeStatus::Active,
            'user_id' => $userId,
        ]);
    }

    private function actAs(User $user): void
    {
        $this->connectTenant('acme');
        $this->actingAs($user)->withSession(['login.tenant_id' => $this->acme()->id]);
    }

    /**
     * @param  list<string>  $permissionSlugs
     */
    private function userWith(array $permissionSlugs, ?Employee $employee = null): User
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        if ($employee !== null && $employee->user_id !== null) {
            $user = User::findOrFail($employee->user_id);
        } else {
            $user = User::create([
                'name' => "Perf Api User {$sequence}",
                'email' => "perf.api.user.{$sequence}@flowsync.test",
                'password' => 'password',
            ]);
        }

        $role = Role::create([
            'name' => "Perf Api Role {$sequence}",
            'slug' => "perf-api-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }
}
