<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveType;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Support\Carbon;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P6.4c — exemption asks over HTTP.
 *
 * Anyone raises their own; the queue takes `hrms.leave.manage`, and the
 * single verdict endpoint approves (recording intermediate steps) or
 * rejects with a reason. Nothing posts to the ledger — the exemption gate
 * (`hrms.leave.exemption`) moves separately from leave administration.
 */
class HrmsLeaveExemptionApiTest extends TestCase
{
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setAcmeModules(['hrms.core', 'hrms.leave', 'hrms.leave.exemption']);
    }

    public function test_an_employee_raises_their_own_exemption(): void
    {
        $user = $this->userWith(['hrms.view']);
        $employee = $this->makeEmployee('Exempt Self', ['user_id' => $user->id]);
        $this->actAs($user);

        $type = LeaveType::query()->where('code', 'annual')->firstOrFail();

        $body = $this->postJson('/api/hrms/leave/exemptions', [
            'leave_type_id' => $type->id,
            'from_date' => $this->daysAgo(10),
            'to_date' => $this->daysAgo(8),
            'days' => 3,
            'reason' => 'Statutory ground.',
        ])->assertCreated()->json();

        $this->assertSame('pending', $body['exemption']['status']);
        $this->assertSame(3.0, (float) $body['exemption']['days']);
        $this->assertNotNull($body['exemption']['approval']);
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'leave.exemption_requested')->exists());
    }

    public function test_the_queue_scopes_to_self_unless_managing(): void
    {
        $reader = $this->userWith(['hrms.view']);
        $this->makeEmployee('Reader', ['user_id' => $reader->id]);
        $other = $this->userWith(['hrms.view']);
        $otherEmployee = $this->makeEmployee('Other', ['user_id' => $other->id]);
        $type = LeaveType::query()->where('code', 'annual')->firstOrFail();

        $this->actAs($other);
        $otherAsk = $this->postJson('/api/hrms/leave/exemptions', [
            'leave_type_id' => $type->id,
            'from_date' => $this->daysAgo(10),
            'to_date' => $this->daysAgo(8),
            'days' => 3,
            'reason' => 'Mine.',
        ])->assertCreated()->json('exemption.id');

        $this->actAs($reader);
        $this->assertSame([], $this->getJson('/api/hrms/leave/exemptions')->assertOk()->json('exemptions'));
        $this->getJson("/api/hrms/leave/exemptions/{$otherAsk}")->assertForbidden();

        $this->actAs($this->userWith(['hrms.view', 'hrms.leave.manage']));
        $all = $this->getJson('/api/hrms/leave/exemptions')->assertOk()->json('exemptions');
        $this->assertSame([$otherAsk], array_column($all, 'id'));
    }

    public function test_decide_approves_through_the_chain_and_rejects_with_reason(): void
    {
        [$manager, $report] = $this->reportingLine();
        $type = LeaveType::query()->where('code', 'annual')->firstOrFail();
        $this->actAs($report->user);

        $id = $this->postJson('/api/hrms/leave/exemptions', [
            'leave_type_id' => $type->id,
            'from_date' => $this->daysAgo(10),
            'to_date' => $this->daysAgo(8),
            'days' => 3,
            'reason' => 'Statutory ground.',
        ])->assertCreated()->json('exemption.id');

        $this->actAs($this->userWith(['hrms.view', 'hrms.leave.manage']));
        $this->postJson("/api/hrms/leave/exemptions/{$id}/decide", ['decision' => 'approve'])
            ->assertForbidden();

        // Manager, manager-again for the fallback seat, then HR.
        $this->actAs($manager->user);
        $this->postJson("/api/hrms/leave/exemptions/{$id}/decide", ['decision' => 'approve'])
            ->assertOk()->assertJsonPath('exemption.status', 'pending');
        $this->postJson("/api/hrms/leave/exemptions/{$id}/decide", ['decision' => 'approve'])->assertOk();

        $this->actAs($this->hrUser());
        $body = $this->postJson("/api/hrms/leave/exemptions/{$id}/decide", ['decision' => 'approve'])
            ->assertOk()->json();

        $this->assertSame('approved', $body['exemption']['status']);
        $this->assertSame('Exemption approved.', $body['message']);
    }

    public function test_decide_rejects_with_a_reason_and_needs_a_verdict(): void
    {
        [$manager, $report] = $this->reportingLine();
        $type = LeaveType::query()->where('code', 'annual')->firstOrFail();
        $this->actAs($report->user);

        $id = $this->postJson('/api/hrms/leave/exemptions', [
            'leave_type_id' => $type->id,
            'from_date' => $this->daysAgo(10),
            'to_date' => $this->daysAgo(8),
            'days' => 3,
            'reason' => 'Statutory ground.',
        ])->assertCreated()->json('exemption.id');

        $this->actAs($manager->user);
        $this->postJson("/api/hrms/leave/exemptions/{$id}/decide", ['decision' => 'reject'])
            ->assertUnprocessable()->assertJsonValidationErrors('decision_note');

        $this->postJson("/api/hrms/leave/exemptions/{$id}/decide", ['note' => 'No note, no verdict.'])
            ->assertUnprocessable()->assertJsonValidationErrors('decision');

        $this->postJson("/api/hrms/leave/exemptions/{$id}/decide", ['decision' => 'reject', 'note' => 'Wrong statute.'])
            ->assertOk()->assertJsonPath('exemption.status', 'rejected');
    }

    public function test_exemptions_gate_on_their_own_module(): void
    {
        $this->setAcmeModules(['hrms.core', 'hrms.leave']);
        $this->loginAdmin();

        $this->getJson('/api/hrms/leave/exemptions')->assertForbidden();

        $this->setAcmeModules(['hrms.core', 'hrms.leave', 'hrms.leave.exemption']);

        $this->getJson('/api/hrms/leave/exemptions')->assertOk();
    }

    // ------------------------------------------------------------ helpers

    /**
     * A manager with a login and one report with a login.
     *
     * @return array{Employee, Employee} Each carrying `->user`.
     */
    private function reportingLine(): array
    {
        $managerUser = $this->makeUser();
        $manager = $this->makeEmployee('Exempt Manager', ['user_id' => $managerUser->id]);
        $manager->user = $managerUser;

        $reportUser = $this->makeUser();
        $report = $this->makeEmployee('Exempt Report', ['user_id' => $reportUser->id, 'manager_id' => $manager->id]);
        $report->user = $reportUser;

        return [$manager, $report];
    }

    private function daysAgo(int $days): string
    {
        return Carbon::today()->subDays($days)->toDateString();
    }

    private function loginAdmin(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();
        $this->connectTenant('acme');
    }

    private function actAs(User $user): void
    {
        $this->connectTenant('acme');
        $this->actingAs($user)->withSession(['login.tenant_id' => $this->acme()->id]);
    }

    private function hrUser(): User
    {
        $role = Role::query()->where('slug', 'hr_manager')->firstOrFail();
        $user = $this->makeUser();
        $user->roles()->sync([$role->id]);

        return $user;
    }

    /**
     * @param  list<string>  $permissionSlugs
     */
    private function userWith(array $permissionSlugs): User
    {
        static $sequence = 0;

        $sequence++;

        $user = User::create([
            'name' => "Exempt User {$sequence}",
            'email' => "exempt.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "Exempt Role {$sequence}",
            'slug' => "exempt-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }

    private function makeUser(): User
    {
        static $sequence = 0;

        $sequence++;

        return User::create([
            'name' => "Exempt Login {$sequence}",
            'email' => "exempt.login.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEmployee(string $name, array $overrides = []): Employee
    {
        static $sequence = 0;

        $sequence++;

        return Employee::create([
            'employee_code' => 'EMP-LVX-'.$sequence,
            'name' => $name,
            'status' => EmployeeStatus::Active,
            ...$overrides,
        ]);
    }

    private function setAcmeModules(array $modules): void
    {
        $plan = app(SubscriptionPlan::class)->where('slug', 'pro')->firstOrFail();
        $plan->update(['limits' => array_merge($plan->limits, ['modules' => $modules])]);

        app(SubscriptionService::class)->assign($this->acme(), $plan->fresh());
    }
}
