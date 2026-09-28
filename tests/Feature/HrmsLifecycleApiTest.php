<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Document\DocumentType;
use App\Models\Hrms\Employee\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P4.3 — the onboarding/offboarding HTTP surface.
 *
 * The service rules are covered in `HrmsLifecycleServiceTest`. What is worth
 * protecting *here* is the part the services cannot see: the module gate and
 * `hrms.view` deciding whether the surface exists, the policies deciding per
 * record (including the self-service cases no tenant permission grants), the
 * nested-param rule (a task id from another run 404s rather than completing
 * the wrong checklist), and the request classes owning input validation.
 */
class HrmsLifecycleApiTest extends TestCase
{
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_a_tenant_without_the_hrms_module_has_no_lifecycle_surface(): void
    {
        $this->setAcmeModules([]);
        $this->login('admin@flowsync.test');

        $this->getJson('/api/hrms/onboarding/templates')->assertForbidden();
        $this->getJson('/api/hrms/onboarding/cases')->assertForbidden();
        $this->getJson('/api/hrms/offboarding/cases')->assertForbidden();
        $this->getJson('/api/hrms/document-requests')->assertForbidden();
    }

    public function test_a_user_without_the_hrms_permission_is_refused(): void
    {
        $this->actAs($this->userWith([]));

        $this->getJson('/api/hrms/onboarding/templates')->assertForbidden();
        $this->postJson('/api/hrms/onboarding/cases', [])->assertForbidden();
    }

    public function test_templates_are_managed_but_readable(): void
    {
        $reader = $this->userWith(['hrms.view', 'hrms.onboarding.view']);
        $this->actAs($reader);

        $this->getJson('/api/hrms/onboarding/templates')->assertOk();
        $this->postJson('/api/hrms/onboarding/templates', ['name' => 'Denied'])->assertForbidden();

        $this->login('admin@flowsync.test');

        $templateId = $this->postJson('/api/hrms/onboarding/templates', [
            'name' => 'Standard Hire',
            'tasks' => [[
                'title' => 'Sign the contract',
                'category' => 'task',
                'owner_scope' => 'hr',
                'is_mandatory' => true,
            ]],
        ])->assertCreated()->json('template.id');

        // Nested task writes ride the template’s own id.
        $itemId = $this->postJson("/api/hrms/onboarding/templates/{$templateId}/tasks", [
            'title' => 'Bring a passport photo',
            'category' => 'document',
            'owner_scope' => 'employee',
        ])->assertCreated()->json('task.id');

        $this->putJson("/api/hrms/onboarding/templates/{$templateId}/tasks/{$itemId}", [
            'title' => 'Bring two passport photos',
        ])->assertOk()->assertJsonPath('task.title', 'Bring two passport photos');

        // A task id from another template 404s rather than editing this one.
        $otherId = $this->postJson('/api/hrms/onboarding/templates', ['name' => 'Other'])->assertCreated()->json('template.id');
        $this->putJson("/api/hrms/onboarding/templates/{$otherId}/tasks/{$itemId}", ['title' => 'Hijacked'])
            ->assertNotFound();

        $this->postJson("/api/hrms/onboarding/templates/{$templateId}/tasks/reorder", [
            'ordered_ids' => [$itemId],
        ])->assertOk();

        $this->deleteJson("/api/hrms/onboarding/templates/{$templateId}/tasks/{$itemId}")->assertOk();
        $this->deleteJson("/api/hrms/onboarding/templates/{$templateId}")->assertOk();
        $this->deleteJson("/api/hrms/onboarding/templates/{$otherId}")->assertOk();
    }

    public function test_a_case_runs_end_to_end_over_http(): void
    {
        $this->login('admin@flowsync.test');
        $employee = $this->makeEmployee('New Hire', ['joining_date' => '2026-10-05']);

        $templateId = $this->postJson('/api/hrms/onboarding/templates', [
            'name' => 'HTTP Hire',
            'tasks' => [
                ['title' => 'Sign contract', 'category' => 'task', 'owner_scope' => 'hr', 'is_mandatory' => true],
                ['title' => 'Bring passport', 'category' => 'document', 'owner_scope' => 'employee'],
            ],
        ])->assertCreated()->json('template.id');

        $body = $this->postJson('/api/hrms/onboarding/cases', [
            'employee_id' => $employee->id,
            'template_id' => $templateId,
        ])->assertCreated()->json('case');

        $this->assertCount(2, $body['tasks']);
        $this->assertSame(0, $body['progress']['percent']);
        $this->assertCount(1, $body['requests']);

        [$mandatory, $document] = [$body['tasks'][0]['id'], $body['tasks'][1]['id']];
        $caseId = $body['id'];

        $this->postJson("/api/hrms/onboarding/cases/{$caseId}/tasks/{$mandatory}/complete", [])
            ->assertOk()->assertJsonPath('task.status', 'done');

        // The document item completes like any other — while its request
        // tracks the file separately.
        $this->postJson("/api/hrms/onboarding/cases/{$caseId}/tasks/{$document}/complete", [])
            ->assertOk();

        $this->postJson("/api/hrms/onboarding/cases/{$caseId}/complete")
            ->assertOk()->assertJsonPath('case.status', 'completed');
    }

    public function test_a_task_id_from_another_case_404s(): void
    {
        $this->login('admin@flowsync.test');
        $templateId = $this->postJson('/api/hrms/onboarding/templates', [
            'name' => 'T',
            'tasks' => [['title' => 'Shared item', 'category' => 'task', 'owner_scope' => 'hr']],
        ])->assertCreated()->json('template.id');
        $first = $this->postJson('/api/hrms/onboarding/cases', [
            'employee_id' => $this->makeEmployee('First')->id,
            'template_id' => $templateId,
        ])->assertCreated()->json('case');
        $second = $this->postJson('/api/hrms/onboarding/cases', [
            'employee_id' => $this->makeEmployee('Second')->id,
            'template_id' => $templateId,
        ])->assertCreated()->json('case');

        // The policy passes (admin manages everything) — the 404 comes from
        // the belonging check, which is exactly the layer that must catch a
        // task id borrowed from another hire’s run.
        $this->postJson("/api/hrms/onboarding/cases/{$first['id']}/tasks/{$second['tasks'][0]['id']}/complete", [])
            ->assertNotFound();

        // And the task completes fine under its own case.
        $this->postJson("/api/hrms/onboarding/cases/{$second['id']}/tasks/{$second['tasks'][0]['id']}/complete", [])
            ->assertOk();
    }

    public function test_a_hire_reads_their_own_case_but_touches_nothing(): void
    {
        $user = $this->userWith(['hrms.view']);
        $mine = $this->makeEmployee('My First Day', ['user_id' => $user->id]);
        $theirs = $this->makeEmployee('Someone Else');

        $this->login('admin@flowsync.test');
        $templateId = $this->postJson('/api/hrms/onboarding/templates', ['name' => 'T'])->assertCreated()->json('template.id');
        $mineId = $this->postJson('/api/hrms/onboarding/cases', [
            'employee_id' => $mine->id, 'template_id' => $templateId,
        ])->assertCreated()->json('case.id');
        $theirsId = $this->postJson('/api/hrms/onboarding/cases', [
            'employee_id' => $theirs->id, 'template_id' => $templateId,
        ])->assertCreated()->json('case.id');

        $this->actAs($user);

        $this->getJson("/api/hrms/onboarding/cases/{$mineId}")->assertOk();
        $this->getJson("/api/hrms/onboarding/cases/{$theirsId}")->assertForbidden();
        $this->getJson('/api/hrms/onboarding/cases')->assertOk()->assertJsonCount(1, 'cases');

        // Reading is not running: starting, completing and waiving are HR’s.
        $this->postJson('/api/hrms/onboarding/cases', ['employee_id' => $mine->id, 'template_id' => $templateId])
            ->assertForbidden();
        $this->postJson("/api/hrms/onboarding/cases/{$mineId}/complete")->assertForbidden();
    }

    public function test_an_exit_refuses_clear_over_http_until_nothing_is_open(): void
    {
        $this->login('admin@flowsync.test');
        $employee = $this->makeEmployee('Leaver');

        $caseId = $this->postJson('/api/hrms/offboarding/cases', [
            'employee_id' => $employee->id,
            'last_working_day' => '2026-12-31',
            'reason' => 'resigned',
        ])->assertCreated()->json('case.id');

        $this->postJson("/api/hrms/offboarding/cases/{$caseId}/clear")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('form');

        $tasks = $this->getJson("/api/hrms/offboarding/cases/{$caseId}")->json('case.tasks');

        foreach ($tasks as $task) {
            $this->postJson("/api/hrms/offboarding/cases/{$caseId}/tasks/{$task['id']}/complete", [])
                ->assertOk();
        }

        $this->postJson("/api/hrms/offboarding/cases/{$caseId}/clear")
            ->assertOk()->assertJsonPath('clearance.dues_settled', true);
        $this->postJson("/api/hrms/offboarding/cases/{$caseId}/complete")
            ->assertOk()->assertJsonPath('case.status', 'completed');
    }

    public function test_terminating_over_http_opens_the_exit_run(): void
    {
        $this->login('admin@flowsync.test');
        $employee = $this->makeEmployee('Terminated');

        $this->postJson("/api/hrms/employees/{$employee->id}/terminate", ['reason' => 'Role eliminated.'])
            ->assertOk();

        $cases = $this->getJson('/api/hrms/offboarding/cases')->assertOk()->json('cases');
        $mine = collect($cases)->firstWhere('employee.id', $employee->id);

        $this->assertNotNull($mine, 'Terminating must open the exit run.');
        $this->assertCount(5, $mine['tasks']);
    }

    public function test_document_asks_submit_and_answer_over_http(): void
    {
        $this->login('admin@flowsync.test');
        $employee = $this->makeEmployee('Asked');

        $requestId = $this->postJson('/api/hrms/document-requests', [
            'employee_id' => $employee->id,
            'title' => 'Bring your passport',
        ])->assertCreated()->json('request.id');

        // The employee submits their own file...
        $user = $this->userWith(['hrms.view']);
        $employee->update(['user_id' => $user->id]);
        $this->actAs($user);

        $documentId = $this->post('/api/hrms/documents', [
            'employee_id' => $employee->id,
            'document_type_id' => $this->documentTypeId(),
            'title' => 'Passport',
            'file' => UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
        ])->assertCreated()->json('document.id');

        $this->postJson("/api/hrms/document-requests/{$requestId}/submit", ['document_id' => $documentId])
            ->assertOk()->assertJsonPath('request.status', 'submitted');

        // ...and HR answers it.
        $this->login('admin@flowsync.test');

        $this->postJson("/api/hrms/document-requests/{$requestId}/accept")
            ->assertOk()->assertJsonPath('request.status', 'accepted');

        $this->getJson('/api/hrms/document-requests')->assertOk()->assertJsonCount(1, 'requests');
    }

    public function test_a_waive_without_a_reason_is_a_422(): void
    {
        $this->login('admin@flowsync.test');
        $employee = $this->makeEmployee('Waived');
        $templateId = $this->postJson('/api/hrms/onboarding/templates', [
            'name' => 'T',
            'tasks' => [['title' => 'Item', 'category' => 'task', 'owner_scope' => 'hr']],
        ])->assertCreated()->json('template.id');
        $caseId = $this->postJson('/api/hrms/onboarding/cases', [
            'employee_id' => $employee->id, 'template_id' => $templateId,
        ])->assertCreated()->json('case.id');
        $taskId = $this->getJson("/api/hrms/onboarding/cases/{$caseId}")->json('case.tasks.0.id');

        $this->postJson("/api/hrms/onboarding/cases/{$caseId}/tasks/{$taskId}/waive", [])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    // ------------------------------------------------------------ helpers

    private function login(string $email): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
        $this->connectTenant('acme');
    }

    private function actAs(User $user): void
    {
        $this->connectTenant('acme');
        $this->actingAs($user)->withSession(['login.tenant_id' => $this->acme()->id]);
    }

    /**
     * @param  list<string>  $permissionSlugs
     */
    private function userWith(array $permissionSlugs): User
    {
        static $sequence = 0;

        $sequence++;

        $user = User::create([
            'name' => "Lifecycle API User {$sequence}",
            'email' => "lifecycle.api.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "Lifecycle API Role {$sequence}",
            'slug' => "lifecycle-api-role-{$sequence}",
        ]);

        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        $user->roles()->sync([$role->id]);

        return $user->fresh(['roles.permissions']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEmployee(string $name, array $overrides = []): Employee
    {
        static $sequence = 0;

        $sequence++;

        return Employee::create([
            'employee_code' => 'EMP-LCA-'.$sequence,
            'name' => $name,
            'status' => EmployeeStatus::Active,
            ...$overrides,
        ]);
    }

    private function documentTypeId(): int
    {
        return DocumentType::query()->where('slug', 'passport')->firstOrFail()->id;
    }

    private function setAcmeModules(array $modules): void
    {
        $plan = app(SubscriptionPlan::class)->where('slug', 'pro')->firstOrFail();
        $plan->update(['limits' => array_merge($plan->limits, ['modules' => $modules])]);

        app(SubscriptionService::class)->assign($this->acme(), $plan->fresh());
    }
}
