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

    public function test_a_legacy_workspaces_view_write_token_keeps_read_and_create_but_gains_nothing(): void
    {
        $ctx = $this->setupProject();
        $project = $ctx['project']->id;
        $legacy = $this->issue(['abilities' => ['workspaces.view'], 'can_write' => true]);
        $full = $this->issue(['name' => 'FullAgent']);

        $this->getJson('/api/v1/projects', $this->bearer($legacy))->assertOk();
        $taskId = $this->postJson("/api/v1/projects/{$project}/tasks", ['title' => 'Legacy create'], $this->bearer($legacy))
            ->assertCreated()->json('task.id');

        // Before the capability groups a write token could only create a task; it must not gain the rest.
        $this->putJson("/api/v1/projects/{$project}/tasks/{$taskId}", ['title' => 'x'], $this->bearer($legacy))
            ->assertForbidden()->assertJsonPath('code', 'token_ability_missing');
        $this->postJson("/api/v1/projects/{$project}/tasks/{$taskId}/move", ['status_id' => 1], $this->bearer($legacy))->assertForbidden();
        $this->postJson("/api/v1/projects/{$project}/tasks/{$taskId}/comments", ['comment' => 'x'], $this->bearer($legacy))->assertForbidden();
        $this->postJson("/api/v1/projects/{$project}/tasks/{$taskId}/work-logs", ['description' => 'x', 'started_at' => now()->subHour()->toIso8601String()], $this->bearer($legacy))->assertForbidden();

        // A token that carries tms.write is unaffected.
        $this->putJson("/api/v1/projects/{$project}/tasks/{$taskId}", ['title' => 'Renamed'], $this->bearer($full))->assertOk();
    }

    public function test_a_token_with_tms_read_but_not_tms_write_cannot_create_even_if_writable(): void
    {
        $ctx = $this->setupProject();
        $token = $this->issue(['abilities' => ['tms.read'], 'can_write' => true]);

        $this->postJson("/api/v1/projects/{$ctx['project']->id}/tasks", ['title' => 'No'], $this->bearer($token))
            ->assertForbidden()->assertJsonPath('code', 'token_ability_missing');
    }

    public function test_a_read_only_token_is_refused_on_every_write_route(): void
    {
        $ctx = $this->setupProject();
        $project = $ctx['project']->id;
        $writer = $this->issue();
        $taskId = $this->postJson("/api/v1/projects/{$project}/tasks", ['title' => 'Target'], $this->bearer($writer))->assertCreated()->json('task.id');
        $readOnly = $this->issue(['name' => 'ReadOnly', 'can_write' => false]);

        foreach ([
            ['post', "/api/v1/projects/{$project}/tasks", ['title' => 'x']],
            ['put', "/api/v1/projects/{$project}/tasks/{$taskId}", ['title' => 'x']],
            ['post', "/api/v1/projects/{$project}/tasks/{$taskId}/move", ['status_id' => 1]],
            ['post', "/api/v1/projects/{$project}/tasks/{$taskId}/comments", ['comment' => 'x']],
            ['post', "/api/v1/projects/{$project}/tasks/{$taskId}/work-logs", ['description' => 'x', 'started_at' => now()->subHour()->toIso8601String()]],
        ] as [$method, $uri, $body]) {
            $this->{$method.'Json'}($uri, $body, $this->bearer($readOnly))
                ->assertForbidden()->assertJsonPath('code', 'token_read_only');
        }
    }

    public function test_an_agent_written_markdown_description_is_stored_as_sanitised_html(): void
    {
        $ctx = $this->setupProject();
        $project = $ctx['project']->id;
        $token = $this->issue();

        $created = $this->postJson("/api/v1/projects/{$project}/tasks", [
            'title' => 'Markdown task',
            'description' => "**Goal**\n\n- one\n- two\n\n<script>alert(1)</script>",
        ], $this->bearer($token))->assertCreated()->json('task');

        $html = Task::findOrFail($created['id'])->description;
        $this->assertStringContainsString('<strong>Goal</strong>', $html);
        $this->assertStringContainsString('<li>one</li>', $html);
        $this->assertStringNotContainsString('<script', $html);

        $this->putJson("/api/v1/projects/{$project}/tasks/{$created['id']}", ['description' => '_edited_'], $this->bearer($token))->assertOk();
        $this->assertStringContainsString('<em>edited</em>', Task::findOrFail($created['id'])->description);
    }

    public function test_the_token_form_offers_the_capability_groups_and_no_longer_the_legacy_ability(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();

        $slugs = collect($this->getJson('/api/api-tokens')->assertOk()->json('abilities'))->pluck('slug')->all();

        $this->assertContains('tms.read', $slugs);
        $this->assertContains('tms.write', $slugs);
        $this->assertNotContains('workspaces.view', $slugs);
    }

    public function test_a_refused_exited_employee_token_leaves_an_audit_row(): void
    {
        $token = $this->issue(['abilities' => ['tms.read'], 'can_write' => false], 'editor@flowsync.test');
        $this->connectTenant('acme');
        Employee::factory()->for(User::where('email', 'editor@flowsync.test')->firstOrFail())->create(['status' => EmployeeStatus::Exited]);

        $this->getJson('/api/v1/me', $this->bearer($token))->assertUnauthorized();

        $row = DB::connection(config('tenancy.system.connection'))->table('audit_logs')
            ->where('action', 'auth.login_failed')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertStringContainsString('employee_exited', (string) $row->data);
    }

    public function test_a_move_that_needs_approval_answers_202_and_does_not_move_the_task(): void
    {
        $this->connectTenant('acme');
        $editor = User::where('email', 'editor@flowsync.test')->firstOrFail();
        $ctx = $this->setupProject();
        $ctx['project']->members()->attach($editor->id, [
            'project_role_id' => ProjectRole::where('slug', 'developer')->firstOrFail()->id,
            'added_by' => $ctx['user']->id,
        ]);
        $token = $this->issue(['abilities' => ['tms.read', 'tms.write'], 'can_write' => true], 'editor@flowsync.test');

        $project = $ctx['project']->id;
        $task = $this->postJson("/api/v1/projects/{$project}/tasks", ['title' => 'Needs approval'], $this->bearer($token))->assertCreated()->json('task');
        $target = TaskStatus::where('project_id', $project)->where('id', '!=', $task['status_id'])->orderBy('position')->firstOrFail();
        $target->update(['entry_rules' => ['approval']]);

        $this->postJson("/api/v1/projects/{$project}/tasks/{$task['id']}/move", ['status_id' => $target->id], $this->bearer($token))
            ->assertStatus(202)->assertJsonPath('pending_approval', true);
        $this->assertSame((int) $task['status_id'], (int) Task::findOrFail($task['id'])->status_id);

        // The edit route refuses the same status change instead of moving silently.
        $this->putJson("/api/v1/projects/{$project}/tasks/{$task['id']}", ['status_id' => $target->id], $this->bearer($token))
            ->assertStatus(422);
    }
}
