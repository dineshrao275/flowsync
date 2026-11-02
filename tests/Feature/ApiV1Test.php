<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Activity;
use App\Models\ApiToken;
use App\Models\Hrms\Employee\Employee;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Hrms\Employee\EmployeeStatusTransition;
use Illuminate\Support\Facades\DB;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class ApiV1Test extends TestCase
{
    use IsolatesDatabase;

    private function issue(array $over = [], string $email = 'admin@flowsync.test'): string
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
        $plain = $this->postJson('/api/api-tokens', $over + [
            'name' => 'AgentToken',
            'abilities' => ['tms.read', 'tms.write'],
            'can_write' => true,
        ])->assertCreated()->json('plaintext');
        $this->postJson('/api/auth/logout')->assertOk();
        $this->flushSession();

        return $plain;
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    private function setupProject(string $email = 'admin@flowsync.test'): array
    {
        $this->connectTenant('acme');
        $user = User::where('email', $email)->firstOrFail();
        $ws = Workspace::create(['created_by' => $user->id, 'name' => 'Engineering', 'slug' => 'eng-'.uniqid()]);
        $ws->members()->attach($user->id, ['role' => 'owner', 'added_by' => $user->id]);

        $project = Project::create([
            'workspace_id' => $ws->id,
            'created_by' => $user->id,
            'lead_user_id' => $user->id,
            'name' => 'Core App',
            'key' => 'APP',
        ]);
        $project->members()->attach($user->id, [
            'project_role_id' => ProjectRole::where('slug', 'lead')->firstOrFail()->id,
            'added_by' => $user->id,
        ]);

        $statuses = [];
        foreach (config('task_statuses.statuses') as $s) {
            $statuses[$s['slug']] = TaskStatus::create([
                'project_id' => $project->id,
                'name' => $s['name'],
                'slug' => $s['slug'],
                'category' => $s['category'],
                'position' => $s['position'],
                'color' => $s['color'],
                'is_done' => $s['is_done'],
                'is_default' => $s['is_default'] ?? false,
            ]);
        }

        return ['workspace' => $ws, 'project' => $project, 'statuses' => $statuses, 'user' => $user];
    }

    public function test_get_me_returns_enriched_modules_products_and_usage(): void
    {
        $token = $this->issue();
        $res = $this->getJson('/api/v1/me', $this->bearer($token))->assertOk()->json();

        $this->assertArrayHasKey('user', $res);
        $this->assertArrayHasKey('tenant', $res);
        $this->assertArrayHasKey('token', $res);
        $this->assertArrayHasKey('modules', $res);
        $this->assertArrayHasKey('products', $res);
        $this->assertArrayHasKey('usage', $res);

        $this->assertSame(60, $res['token']['rate_limit']);
        $this->assertTrue($res['products']['tms']);
        $this->assertArrayHasKey('api_calls', $res['usage']);
        $this->assertArrayHasKey('used', $res['usage']['api_calls']);
    }

    public function test_workspaces_and_projects_can_be_read_via_token(): void
    {
        $ctx = $this->setupProject();
        $token = $this->issue();

        // Workspaces list & detail
        $wsRes = $this->getJson('/api/v1/workspaces', $this->bearer($token))->assertOk()->json();
        $this->assertNotEmpty($wsRes['workspaces']);

        $wsDetail = $this->getJson("/api/v1/workspaces/{$ctx['workspace']->id}", $this->bearer($token))->assertOk()->json();
        $this->assertSame('Engineering', $wsDetail['workspace']['name']);

        // Projects list & detail
        $pRes = $this->getJson('/api/v1/projects', $this->bearer($token))->assertOk()->json();
        $this->assertNotEmpty($pRes['projects']);

        $pDetail = $this->getJson("/api/v1/projects/{$ctx['project']->id}", $this->bearer($token))->assertOk()->json();
        $this->assertSame('Core App', $pDetail['project']['name']);
        $this->assertArrayHasKey('statuses', $pDetail['project']);
        $this->assertArrayHasKey('members', $pDetail['project']);
    }

    public function test_task_lifecycle_and_reads_do_not_record_views(): void
    {
        $ctx = $this->setupProject();
        $token = $this->issue();

        // Create task
        $createRes = $this->postJson("/api/v1/projects/{$ctx['project']->id}/tasks", [
            'title' => 'Build MCP server',
            'description' => 'Complete task implementation',
        ], $this->bearer($token))->assertCreated()->json();

        $taskId = $createRes['task']['id'];
        $taskKey = $createRes['task']['key'];

        // Verify task view record was NOT stamped on read
        DB::table('task_views')->truncate();
        $this->getJson("/api/v1/projects/{$ctx['project']->id}/tasks/{$taskId}", $this->bearer($token))->assertOk();
        $this->assertSame(0, DB::table('task_views')->count());

        $this->getJson("/api/v1/projects/{$ctx['project']->id}/tasks/key/{$taskKey}", $this->bearer($token))->assertOk();
        $this->assertSame(0, DB::table('task_views')->count());

        // Update task and verify activity attribution
        $this->putJson("/api/v1/projects/{$ctx['project']->id}/tasks/{$taskId}", [
            'title' => 'Build MCP server v1',
        ], $this->bearer($token))->assertOk();

        $activity = Activity::where('subject_type', Task::class)->where('subject_id', $taskId)->latest('id')->first();
        $this->assertNotNull($activity);
        $this->assertSame('api', $activity->data['via'] ?? null);

        // Transitions tool
        $trans = $this->getJson("/api/v1/projects/{$ctx['project']->id}/tasks/{$taskId}/transitions", $this->bearer($token))->assertOk()->json();
        $this->assertArrayHasKey('transitions', $trans);

        // Move task
        $targetStatus = $ctx['statuses']['in_progress'] ?? TaskStatus::where('project_id', $ctx['project']->id)->where('id', '!=', $createRes['task']['status_id'])->firstOrFail();
        $this->postJson("/api/v1/projects/{$ctx['project']->id}/tasks/{$taskId}/move", [
            'status_id' => $targetStatus->id,
        ], $this->bearer($token))->assertOk();

        // Comments
        $this->postJson("/api/v1/projects/{$ctx['project']->id}/tasks/{$taskId}/comments", [
            'comment' => 'Agent automated comment @admin',
        ], $this->bearer($token))->assertCreated();

        $comments = $this->getJson("/api/v1/projects/{$ctx['project']->id}/tasks/{$taskId}/comments", $this->bearer($token))->assertOk()->json();
        $this->assertCount(1, $comments['comments']);

        // Work logs
        $this->postJson("/api/v1/projects/{$ctx['project']->id}/tasks/{$taskId}/work-logs", [
            'duration_minutes' => 45,
            'description' => 'Working on MCP tools',
            'started_at' => now()->subMinutes(45)->toIso8601String(),
        ], $this->bearer($token))->assertCreated();

        $workLogs = $this->getJson("/api/v1/projects/{$ctx['project']->id}/tasks/{$taskId}/work-logs", $this->bearer($token))->assertOk()->json();
        $this->assertNotEmpty($workLogs['work_logs']);

        // Activities endpoint
        $activities = $this->getJson("/api/v1/projects/{$ctx['project']->id}/tasks/{$taskId}/activities", $this->bearer($token))->assertOk()->json();
        $this->assertNotEmpty($activities['activities']);
    }

    public function test_an_exited_employee_cannot_use_existing_api_token(): void
    {
        $token = $this->issue(['abilities' => ['tms.read'], 'can_write' => false], 'editor@flowsync.test');
        $this->connectTenant('acme');
        $user = User::where('email', 'editor@flowsync.test')->firstOrFail();

        // Create an exited employee record for this user
        Employee::factory()->for($user)->create([
            'status' => EmployeeStatus::Exited,
        ]);

        $this->getJson('/api/v1/me', $this->bearer($token))
            ->assertUnauthorized()
            ->assertJsonPath('code', 'employee_exited');
    }

    public function test_transitioning_an_employee_to_exit_revokes_their_api_tokens(): void
    {
        $token = $this->issue(['abilities' => ['tms.read'], 'can_write' => false], 'editor@flowsync.test');
        $this->connectTenant('acme');
        $user = User::where('email', 'editor@flowsync.test')->firstOrFail();
        $employee = Employee::factory()->for($user)->create([
            'status' => EmployeeStatus::Active,
        ]);

        $tokenId = ApiToken::where('user_id', $user->id)->firstOrFail()->id;
        $this->assertNull(ApiToken::find($tokenId)->revoked_at);

        // Transition employee to terminated
        app(EmployeeStatusTransition::class)->terminate($employee, 'Departure', 'End of employment');

        // Token should now be marked revoked
        $this->assertNotNull(ApiToken::find($tokenId)->revoked_at);
        $this->getJson('/api/v1/me', $this->bearer($token))->assertUnauthorized();
    }

    public function test_project_member_can_mint_tms_write_via_project_role(): void
    {
        $this->connectTenant('acme');
        $editor = User::where('email', 'editor@flowsync.test')->firstOrFail();
        $ctx = $this->setupProject();

        // Add editor to project as lead (which grants tasks.create, tasks.edit, tasks.move)
        $ctx['project']->members()->attach($editor->id, [
            'project_role_id' => ProjectRole::where('slug', 'lead')->firstOrFail()->id,
            'added_by' => $ctx['user']->id,
        ]);

        // Editor should now be able to mint tms.write ability
        $this->postJson('/api/auth/login', ['email' => 'editor@flowsync.test', 'password' => 'password'])->assertOk();
        $this->postJson('/api/api-tokens', [
            'name' => 'ProjectLeadAgent',
            'abilities' => ['tms.read', 'tms.write'],
            'can_write' => true,
        ])->assertCreated();
    }
}
