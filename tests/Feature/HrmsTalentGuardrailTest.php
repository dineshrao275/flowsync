<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Schema;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * Phase 24.4 — talent guardrails.
 *
 * Three rules that must survive every future refactor: the calibration
 * room never leaks reviewer material (peers stay anonymous even to
 * calibrators reading the grid), no composite-score column may ever
 * appear on `review_summaries`, and a peer without the manage grant gets
 * a 403 — never another person's rating — through the talent surface.
 */
class HrmsTalentGuardrailTest extends TestCase
{
    use IsolatesDatabase;

    public function test_calibration_grid_rows_carry_no_reviewer_material(): void
    {
        [$manager, $report, $peer] = $this->trio();
        $cycleId = $this->calibrationReadyCycle($report, $peer);
        $this->actAs($this->userWith(['hrms.view', 'hrms.performance.manage', 'hrms.talent.manage']));

        $this->postJson("/api/hrms/performance/cycles/{$cycleId}/reviews", [
            'employee_id' => $report->id, 'manager_rating' => 3,
        ])->assertCreated();

        $asks = $this->getJson("/api/hrms/performance/cycles/{$cycleId}/feedback-requests")->assertOk()->json('feedback_requests');
        $peerAsk = collect($asks)->firstWhere(fn ($ask) => (int) $ask['from']['id'] === $peer->id);

        $this->actAs($this->userWith(['hrms.view'], $peer));
        $this->postJson("/api/hrms/performance/feedback-requests/{$peerAsk['id']}/respond", [
            'action' => 'submit', 'rating' => 5, 'body' => 'Peer words stay private.',
        ])->assertOk();

        $this->actAs($this->userWith(['hrms.view', 'hrms.talent.manage']));

        $rows = $this->getJson("/api/hrms/talent/cycles/{$cycleId}/reviews")->assertOk()->json('reviews');

        foreach ($rows as $row) {
            $this->assertSame(
                [],
                array_values(array_intersect(
                    array_keys($row),
                    ['responses', 'from', 'reviewer', 'body', 'bodies', 'peer', 'peers']
                )),
                'The grid names reviewees, never reviewers.'
            );
        }

        $this->assertStringNotContainsString(
            'Peer words stay private.',
            $this->getJson("/api/hrms/talent/cycles/{$cycleId}/reviews")->getContent()
        );
    }

    public function test_no_composite_score_column_may_appear(): void
    {
        $this->connectTenant('acme');

        foreach (['overall_score', 'average_rating', 'composite_score', 'final_score', 'talent_score'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('review_summaries', $column),
                "review_summaries.{$column} would be a person-score — the founding rule forbids it."
            );
        }
    }

    public function test_peer_without_manage_gets_403_not_ratings(): void
    {
        [$manager, $report, $peer] = $this->trio();
        $cycleId = $this->calibrationReadyCycle($report);

        $this->actAs($this->userWith(['hrms.view'], $peer));

        $this->getJson("/api/hrms/talent/cycles/{$cycleId}/reviews")->assertForbidden();
        $this->getJson("/api/hrms/talent/cycles/{$cycleId}/distribution")->assertForbidden();
    }

    // ------------------------------------------------------------ helpers

    private function calibrationReadyCycle(Employee ...$people): int
    {
        $this->assignProToAcme();
        $this->setAcmeModules(['hrms.core', 'hrms.performance', 'hrms.talent']);
        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');

        $cycle = $this->postJson('/api/hrms/performance/cycles', [
            'name' => 'Talent Guardrails', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31',
        ])->assertCreated()->json('cycle');

        foreach ($people as $person) {
            $this->postJson("/api/hrms/performance/cycles/{$cycle['id']}/goals", [
                'employee_id' => $person->id, 'title' => 'Cycle goal.',
                'metric_type' => 'manual', 'weight' => '100',
            ])->assertCreated();
        }

        foreach (['open-check-in', 'open-self-review', 'open-manager-review', 'open-calibration'] as $step) {
            $this->postJson("/api/hrms/performance/cycles/{$cycle['id']}/{$step}", [])->assertOk();
        }

        return (int) $cycle['id'];
    }

    private function login(string $email): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
    }

    /**
     * @return array{Employee, Employee, Employee}
     */
    private function trio(): array
    {
        $this->connectTenant('acme');

        $managerUser = User::create([
            'name' => 'Guardrail Manager', 'email' => 'guardrail.manager@flowsync.test', 'password' => 'password',
        ]);
        $manager = Employee::create([
            'employee_code' => 'EMP-TG-MGR', 'name' => 'Guardrail Manager',
            'status' => EmployeeStatus::Active, 'user_id' => $managerUser->id,
        ]);
        $manager->user = $managerUser;

        $reportUser = User::create([
            'name' => 'Guardrail Report', 'email' => 'guardrail.report@flowsync.test', 'password' => 'password',
        ]);
        $report = Employee::create([
            'employee_code' => 'EMP-TG-REP', 'name' => 'Guardrail Report',
            'status' => EmployeeStatus::Active, 'user_id' => $reportUser->id, 'manager_id' => $manager->id,
        ]);
        $report->user = $reportUser;

        $peerUser = User::create([
            'name' => 'Guardrail Peer', 'email' => 'guardrail.peer@flowsync.test', 'password' => 'password',
        ]);
        $peer = Employee::create([
            'employee_code' => 'EMP-TG-PEER', 'name' => 'Guardrail Peer',
            'status' => EmployeeStatus::Active, 'user_id' => $peerUser->id, 'manager_id' => $manager->id,
        ]);
        $peer->user = $peerUser;

        return [$manager, $report, $peer];
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
                'name' => "Guardrail User {$sequence}",
                'email' => "guardrail.user.{$sequence}@flowsync.test",
                'password' => 'password',
            ]);
        }

        $role = Role::create([
            'name' => "Guardrail Role {$sequence}",
            'slug' => "guardrail-role-{$sequence}",
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
