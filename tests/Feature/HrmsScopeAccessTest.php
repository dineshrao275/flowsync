<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Document\DocumentType;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Expense\ExpenseClaim;
use App\Models\Hrms\Leave\LeaveRequest;
use App\Models\Hrms\Leave\LeaveType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Hrms\HrmsScope;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Storage;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * Phase C step 10 — HRMS own/assigned/all row scoping.
 *
 * The four step-10 lists (leave, comp-off, expense, document) now answer
 * "which rows may a viewer read" through `HrmsScope`, the same answer the
 * per-record policies read, so what a list offers and what a show opens
 * cannot disagree. An `_assigned` holder sees their own rows plus their
 * direct reports' (via `ReportsTo`, one hop down the reporting line);
 * `_own` and self-service see only themselves; the legacy slug and `manage`
 * still read everything; and a login with no employment record reads an
 * empty list — never a 403, never the tenant.
 */
class HrmsScopeAccessTest extends TestCase
{
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_an_assigned_holder_reads_own_and_direct_reports_asks_over_http(): void
    {
        $this->setAcmeModules(['hrms.leave']);

        $manager = $this->managerUser();
        $report = $this->reportFor($manager->employee);

        $ownAsk = $this->makeLeaveRequest($manager->employee);
        $reportAsk = $this->makeLeaveRequest($report);
        $this->makeLeaveRequest($this->makeEmployee('Stranger'));

        $this->actAs($manager);

        $ids = $this->getJson('/api/hrms/leave/requests')->assertOk()->json('requests.*.id');

        $this->assertCount(2, $ids, 'No ask outside the reporting line may leak in.');
        $this->assertContains($ownAsk->id, $ids, 'An assigned holder must read their own ask.');
        $this->assertContains($reportAsk->id, $ids, 'An assigned holder must read a direct report ask.');

        $this->getJson("/api/hrms/leave/requests/{$reportAsk->id}")->assertOk();
    }

    public function test_an_own_holder_reads_only_their_own_leave_rows(): void
    {
        $this->setAcmeModules(['hrms.leave']);

        $viewer = $this->ownUser();
        $report = $this->reportFor($viewer->employee);

        $ownAskA = $this->makeLeaveRequest($viewer->employee);
        $ownAskB = $this->makeLeaveRequest($viewer->employee);
        $reportAsk = $this->makeLeaveRequest($report);

        $this->actAs($viewer);

        $ids = $this->getJson('/api/hrms/leave/requests')->assertOk()->json('requests.*.id');

        $this->assertCount(2, $ids);
        $this->assertContains($ownAskA->id, $ids);
        $this->assertContains($ownAskB->id, $ids);
        $this->assertNotContains($reportAsk->id, $ids, 'An own-scoped holder must not inherit reports rows.');
    }

    public function test_the_legacy_view_slug_and_manage_still_read_every_ask(): void
    {
        $this->setAcmeModules(['hrms.leave']);

        $ask = $this->makeLeaveRequest($this->makeEmployee('Someone'));

        $legacy = $this->userWith(['hrms.view', 'hrms.leave.view']);

        $this->actAs($legacy);

        $ids = $this->getJson('/api/hrms/leave/requests')->assertOk()->json('requests.*.id');
        $this->assertContains($ask->id, $ids, 'The legacy view slug has always meant every row.');

        $manager = $this->userWith(['hrms.view', 'hrms.leave.manage']);

        $this->actAs($manager);

        $ids = $this->getJson('/api/hrms/leave/requests')->assertOk()->json('requests.*.id');
        $this->assertContains($ask->id, $ids, 'Manage is the all-or-nothing gate; it still reads everything.');
    }

    public function test_a_view_holder_with_no_employment_record_reads_no_rows(): void
    {
        $this->setAcmeModules(['hrms.leave']);

        $this->makeLeaveRequest($this->makeEmployee('Someone'));

        $orphan = $this->userWith(['hrms.view', 'hrms.leave.view_own']);

        $this->actAs($orphan);

        $this->assertSame(
            [],
            $this->getJson('/api/hrms/leave/requests')->assertOk()->json('requests.*.id'),
            'A service account with no employment record reads nothing, not everyone.',
        );
    }

    public function test_expense_claims_scope_to_the_reporting_line_for_an_assigned_holder(): void
    {
        $this->setAcmeModules(['hrms.core', 'hrms.expenses']);

        $manager = $this->managerUser();
        $report = $this->reportFor($manager->employee);

        $ownClaim = ExpenseClaim::factory()->create(['employee_id' => $manager->employee->id]);
        $reportClaim = ExpenseClaim::factory()->create(['employee_id' => $report->id]);
        $strangerClaim = ExpenseClaim::factory()->create(['employee_id' => $this->makeEmployee('Stranger')->id]);

        $this->actAs($manager);

        $ids = $this->getJson('/api/hrms/expenses/claims')->assertOk()->json('claims.*.id');

        $this->assertCount(2, $ids);
        $this->assertContains($ownClaim->id, $ids);
        $this->assertContains($reportClaim->id, $ids);
        $this->assertNotContains($strangerClaim->id, $ids);
    }

    public function test_document_rows_follow_the_scope_and_keep_confidential_invisible(): void
    {
        $this->setAcmeModules(['hrms.core', 'hrms.documents']);

        $manager = $this->managerUser();
        $report = $this->reportFor($manager->employee);

        $ownPlain = $this->uploadFor($manager->employee);
        $reportPlain = $this->uploadFor($report);
        $reportSecret = $this->uploadFor($report, ['confidential' => true]);

        $this->actAs($manager);

        $ids = $this->getJson('/api/hrms/documents')->assertOk()->json('documents.*.id');

        $this->assertCount(2, $ids);
        $this->assertContains($ownPlain->id, $ids);
        $this->assertContains($reportPlain->id, $ids, 'An assigned holder reads their reports plain documents.');
        $this->assertNotContains($reportSecret->id, $ids, 'A list that names a confidential row leaks its existence.');

        $this->getJson("/api/hrms/documents/{$reportPlain->id}")->assertOk();
        $this->getJson("/api/hrms/documents/{$reportSecret->id}")->assertForbidden();
    }

    public function test_hrms_scope_resolves_widest_scope_and_the_row_set_together(): void
    {
        $manager = $this->managerUser();
        $report = $this->reportFor($manager->employee);
        $stranger = $this->makeEmployee('Stranger');

        $this->assertSame('assigned', HrmsScope::widestView($manager, 'hrms.leave'));
        $this->assertFalse(HrmsScope::seesAll($manager, 'hrms.leave'), '_assigned is not _all.');
        $this->assertTrue(HrmsScope::canRead($manager, 'hrms.leave'));

        $ids = HrmsScope::employeeIdsFor($manager, 'hrms.leave');

        $this->assertContains($manager->employee->id, $ids);
        $this->assertContains($report->id, $ids);
        $this->assertNotContains($stranger->id, $ids);

        $this->assertTrue(HrmsScope::coversEmployee($manager, 'hrms.leave', $report));
        $this->assertFalse(HrmsScope::coversEmployee($manager, 'hrms.leave', $stranger));

        $own = $this->ownUser();

        $this->assertSame('own', HrmsScope::widestView($own, 'hrms.leave'));
        $this->assertSame([$own->employee->id], HrmsScope::employeeIdsFor($own, 'hrms.leave'));
        $this->assertFalse(HrmsScope::coversEmployee($own, 'hrms.leave', $report));

        $manageOnly = $this->userWith(['hrms.view', 'hrms.leave.manage']);

        $this->assertTrue(HrmsScope::seesAll($manageOnly, 'hrms.leave'));
        $this->assertTrue(HrmsScope::coversEmployee($manageOnly, 'hrms.leave', $stranger));
    }

    private function managerUser(): User
    {
        $user = $this->userWith([
            'hrms.view',
            'hrms.leave.view_own',
            'hrms.leave.view_assigned',
            'hrms.expenses.view_own',
            'hrms.expenses.view_assigned',
            'hrms.documents.view_own',
            'hrms.documents.view_assigned',
        ]);

        $user->setRelation('employee', $this->makeEmployee('Manager', ['user_id' => $user->id]));

        return $user;
    }

    private function ownUser(): User
    {
        $user = $this->userWith(['hrms.view', 'hrms.leave.view_own']);

        $user->setRelation('employee', $this->makeEmployee('Own', ['user_id' => $user->id]));

        return $user;
    }

    private function makeLeaveRequest(Employee $employee): LeaveRequest
    {
        $type = LeaveType::query()->where('code', 'annual')->firstOrFail();

        return LeaveRequest::factory()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
        ]);
    }

    /**
     * @param  list<string>  $permissionSlugs
     */
    private function userWith(array $permissionSlugs): User
    {
        static $sequence = 0;

        $sequence++;

        $user = User::create([
            'name' => "Scope User {$sequence}",
            'email' => "hrms.scope.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "Scope Role {$sequence}",
            'slug' => "hrms-scope-role-{$sequence}",
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

    /**
     * @return Employee A direct report with a login (`user_id` set).
     */
    private function reportFor(Employee $managerEmployee): Employee
    {
        $user = User::create([
            'name' => 'Report User',
            'email' => 'hrms.report.'.uniqid().'@flowsync.test',
            'password' => 'password',
        ]);

        return $this->makeEmployee('Report', [
            'user_id' => $user->id,
            'manager_id' => $managerEmployee->id,
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
            'employee_code' => 'EMP-HSC-'.$sequence,
            'name' => $name,
            'status' => EmployeeStatus::Active,
            ...$overrides,
        ]);
    }

    private function makeType(): DocumentType
    {
        static $sequence = 0;

        $sequence++;

        return DocumentType::create([
            'name' => "Scope Type {$sequence}",
            'slug' => "hrms-scope-type-{$sequence}",
            'category' => 'identity',
            'is_mandatory' => false,
            'requires_expiry' => false,
            'retention_months' => null,
            'is_sensitive' => false,
            'position' => $sequence * 10,
            'is_active' => true,
            'is_system' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function uploadFor(Employee $employee, array $overrides = []): EmployeeDocument
    {
        static $sequence = 0;

        $sequence++;

        $path = "hrms/{$this->acme()->id}/{$employee->id}/fixture-{$sequence}.pdf";
        Storage::disk('local')->put($path, 'fixture-bytes');

        return EmployeeDocument::create([
            'employee_id' => $employee->id,
            'document_type_id' => $this->makeType()->id,
            'title' => "Fixture {$sequence}",
            'file_disk' => 'local',
            'file_path' => $path,
            'original_name' => "fixture-{$sequence}.pdf",
            'mime' => 'application/pdf',
            'size' => 13,
            'status' => 'pending',
            'visibility' => 'hr',
            'confidential' => false,
            'source' => 'hr',
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
