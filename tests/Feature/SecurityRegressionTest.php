<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Attachment;
use App\Models\Hrms\Employee\Employee;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\Tenant;
use App\Models\TenantUserRouting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * Phase 1 security regressions. Each test pins a fixed finding:
 *
 * - C1: the HRMS group runs the full domain stack (unauthenticated 401,
 *   tenant user works even on a production-fresh central connection,
 *   platform SA still 403s).
 * - C2: signed downloads refuse anonymous holders (attachment / document /
 *   photo) while the named reader still downloads.
 * - C3: photos are served from the private disk (no public-disk fixture).
 * - Highs: SVG uploads rejected, forgot-password uniform, tenant JSON hides
 *   DB credentials, over-long passwords rejected.
 */
class SecurityRegressionTest extends TestCase
{
    use IsolatesDatabase;

    private Tenant $acme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->acme = Tenant::where('slug', 'acme')->first();
    }

    // ------------------------------------------------------------ C1: stack

    public function test_unauthenticated_hrms_requests_are_401_not_data(): void
    {
        $this->getJson('/api/hrms/employees')->assertUnauthorized();
        $this->getJson('/api/hrms/org')->assertUnauthorized();
    }

    public function test_hrms_works_on_a_production_fresh_central_connection(): void
    {
        $this->login('admin@flowsync.test');

        // A fresh production process starts on the central DB. Without
        // SwitchTenant in the HRMS group this answered 500.
        DB::setDefaultConnection('iso_system');

        $this->getJson('/api/hrms/employees')->assertOk();
    }

    public function test_platform_super_admin_still_403s_on_hrms(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $this->getJson('/api/hrms/employees')->assertForbidden();
    }

    // ------------------------------------------------------ C2: downloads

    public function test_anonymous_attachment_links_download_nothing(): void
    {
        Storage::fake('local');
        $project = $this->createProjectWithLead();
        $task = $this->makeTask($project);
        $this->login('admin@flowsync.test');
        $adminId = $this->admin()->id;

        $attachment = $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/attachments", [
            'file' => UploadedFile::fake()->create('notes.txt', 5),
        ])->assertCreated()->json('attachment');

        $this->assertStringContainsString('actor='.$adminId, $attachment['download_url']);

        $this->flushSession();
        DB::setDefaultConnection('iso_system');

        // Minted URL (named reader) still downloads with no session.
        $this->get($attachment['download_url'])->assertOk();

        // Same link hand-signed without the reader is refused.
        $anonymous = URL::temporarySignedRoute('attachments.download', now()->addHour(), [
            'task' => $task->id,
            'attachment' => $attachment['id'],
            'tenant' => $this->acme->id,
        ]);
        $this->get($anonymous)->assertForbidden();
    }

    public function test_anonymous_document_and_photo_links_download_nothing(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('hrms/photos/9.png', 'binary');

        $this->connectTenant('acme');
        $employee = Employee::create([
            'employee_code' => 'EMP-SEC-9',
            'name' => 'Security Nine',
            'status' => EmployeeStatus::Active,
            'photo_path' => 'hrms/photos/9.png',
        ]);

        $privileged = $this->userWith(['hrms.view', 'hrms.employees.view', 'hrms.documents.view_sensitive']);
        $this->actAs($privileged);
        $url = $this->getJson("/api/hrms/employees/{$employee->id}")->assertOk()->json('employee.photo_url');
        $this->assertNotNull($url);
        $this->assertStringContainsString('actor=', $url);

        $this->flushSession();
        DB::setDefaultConnection('iso_system');

        // Named reader downloads session-free.
        $this->get($url)->assertOk();

        // No reader → 403, even with a valid signature.
        $anonymous = URL::temporarySignedRoute('hrms.employees.photo', now()->addHour(), [
            'employee' => $employee->id,
            'tenant' => $this->acme->id,
        ]);
        $this->get($anonymous)->assertForbidden();
    }

    // ------------------------------------------------------------- highs

    public function test_svg_uploads_are_rejected(): void
    {
        $project = $this->createProjectWithLead();
        $task = $this->makeTask($project);
        $this->login('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$task->id}/attachments", [
            'file' => UploadedFile::fake()->create('evil.svg', 5, 'image/svg+xml'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_forgot_password_is_uniform_for_unknown_addresses(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@flowsync.test'])
            ->assertOk()
            ->assertJsonMissingPath('status');
    }

    public function test_tenant_json_hides_database_credentials(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $row = $this->getJson('/api/tenants/'.$this->acme->id)->assertOk()->json('tenant');

        foreach (['db_name', 'db_host', 'db_port', 'db_user', 'db_password'] as $key) {
            $this->assertArrayNotHasKey($key, $row);
        }
    }

    public function test_over_long_passwords_are_rejected(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'admin@flowsync.test'])->assertOk();

        $this->postJson('/api/auth/reset-password', [
            'email' => 'admin@flowsync.test',
            'token' => 'bogus',
            'password' => str_repeat('a', 73),
            'password_confirmation' => str_repeat('a', 73),
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    // ---------------------------------------------------------- fixtures

    private function login(string $email): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();

        $this->connectTenant('acme');
    }

    private function actAs(User $user): void
    {
        $this->connectTenant('acme');
        $this->actingAs($user)->withSession(['login.tenant_id' => $this->acme->id]);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@flowsync.test')->first();
    }

    /**
     * @param  list<string>  $permissionSlugs
     */
    private function userWith(array $permissionSlugs): User
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        $user = User::create([
            'name' => "Security User {$sequence}",
            'email' => "security.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);

        $role = Role::create([
            'name' => "Security Role {$sequence}",
            'slug' => "security-role-{$sequence}",
        ]);
        $role->permissions()->sync(
            Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );
        $user->roles()->sync([$role->id]);

        // Same-tenant routing row, or the account cannot log in (isolated mode
        // resolves the tenant from the central index first).
        TenantUserRouting::create([
            'tenant_id' => $this->acme->id,
            'user_id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
        ]);

        return $user;
    }

    private function makeWorkspace(): Workspace
    {
        $workspace = Workspace::create([
            'created_by' => $this->admin()->id,
            'name' => 'Security',
            'slug' => 'security-'.uniqid(),
        ]);
        $workspace->members()->attach($this->admin()->id, ['role' => 'owner', 'added_by' => $this->admin()->id]);

        return $workspace;
    }

    private function createProjectWithLead(): Project
    {
        $workspace = $this->makeWorkspace();
        $project = Project::create([
            'workspace_id' => $workspace->id,
            'created_by' => $this->admin()->id,
            'lead_user_id' => $this->admin()->id,
            'name' => 'Secure',
            'key' => 'SEC'.random_int(100, 999),
        ]);
        $leadRole = ProjectRole::where('slug', 'lead')->first();
        $project->members()->attach($this->admin()->id, ['project_role_id' => $leadRole->id, 'added_by' => $this->admin()->id]);

        $position = 0;
        foreach (config('task_statuses.statuses') as $status) {
            $position++;
            TaskStatus::create([
                'project_id' => $project->id,
                'name' => $status['name'],
                'slug' => $status['slug'],
                'category' => $status['category'],
                'position' => $position,
                'color' => $status['color'] ?? null,
                'is_default' => $status['is_default'] ?? false,
                'is_done' => $status['is_done'] ?? false,
            ]);
        }

        return $project;
    }

    private function makeTask(Project $project): Task
    {
        $project->increment('last_task_sequence');
        $sequence = $project->last_task_sequence;

        return Task::create([
            'project_id' => $project->id,
            'workspace_id' => $project->workspace_id,
            'created_by' => $this->admin()->id,
            'status_id' => $project->statuses()->where('is_default', true)->first()->id,
            'title' => 'Sensitive file host',
            'key' => "{$project->key}-{$sequence}",
            'sequence' => $sequence,
            'position' => 1,
        ]);
    }
}
