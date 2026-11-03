<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Enums\Hrms\GoalMetricType;
use App\Enums\Hrms\GoalProgressSource;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Performance\GoalTaskLink;
use App\Models\Hrms\Performance\PerformanceCycle;
use App\Models\Hrms\Performance\PerformanceGoal;
use App\Models\Hrms\Shared\HrmsSetting;
use App\Models\Hrms\TaskLink\TaskLink;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Route;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * Who can do what through a personal API token (the MCP's only door).
 *
 * A token narrows its owner and never widens them: every call is judged by the same policy the app applies to
 * that person in that project. HRMS is read-only through tokens, structurally, and nothing a token does to TMS
 * data may write HR data (goal evidence, HR-linked tasks, attendance derived from work logs).
 */
class ApiV1AuthorizationMatrixTest extends TestCase
{
    use IsolatesDatabase;

    // ------------------------------------------------------------ TMS: per-user, per-project authorization

    public function test_a_write_token_is_only_as_strong_as_the_owners_role_in_each_project(): void
    {
        $admin = User::where('email', 'admin@flowsync.test')->firstOrFail();
        [$a] = $this->project('AAA', $admin);
        [$b] = $this->project('BBB', $admin);
        $editor = User::where('email', 'editor@flowsync.test')->firstOrFail();
        $this->join($a, $editor, 'developer', $admin);
        $this->join($b, $editor, 'viewer', $admin);

        $adminToken = $this->issue();
        $taskA = $this->createTask($a, $adminToken);
        $taskB = $this->createTask($b, $adminToken);
        $token = $this->issue(['abilities' => ['tms.read', 'tms.write'], 'can_write' => true], 'editor@flowsync.test');

        // Developer in A: every write works.
        $this->putJson("/api/v1/projects/{$a->id}/tasks/{$taskA}", ['title' => 'Edited'], $this->bearer($token))->assertOk();
        $this->postJson("/api/v1/projects/{$a->id}/tasks/{$taskA}/move", ['status_id' => $this->otherStatus($a, $taskA)], $this->bearer($token))->assertOk();
        $this->postJson("/api/v1/projects/{$a->id}/tasks/{$taskA}/work-logs", $this->log(), $this->bearer($token))->assertCreated();
        $this->postJson("/api/v1/projects/{$a->id}/tasks", ['title' => 'New in A'], $this->bearer($token))->assertCreated();

        // Project viewer in B: the same token must not edit, move, create or log time there.
        $this->putJson("/api/v1/projects/{$b->id}/tasks/{$taskB}", ['title' => 'Nope'], $this->bearer($token))->assertForbidden();
        $this->postJson("/api/v1/projects/{$b->id}/tasks/{$taskB}/move", ['status_id' => $this->otherStatus($b, $taskB)], $this->bearer($token))->assertForbidden();
        $this->postJson("/api/v1/projects/{$b->id}/tasks", ['title' => 'Nope'], $this->bearer($token))->assertForbidden();
        $this->postJson("/api/v1/projects/{$b->id}/tasks/{$taskB}/work-logs", $this->log(), $this->bearer($token))->assertForbidden();
        $this->assertSame('Task in BBB', Task::findOrFail($taskB)->title);

        // Reading is allowed, and a project viewer may comment: the role grants comments.create.
        $this->getJson("/api/v1/projects/{$b->id}/tasks/{$taskB}", $this->bearer($token))->assertOk();
        $this->postJson("/api/v1/projects/{$b->id}/tasks/{$taskB}/comments", ['comment' => 'Looks good'], $this->bearer($token))->assertCreated();
    }

    public function test_a_viewer_role_user_cannot_create_any_token_and_a_manager_can(): void
    {
        // The Viewer tenant role has no api.manage: no token at all, so no door for an agent.
        $this->postJson('/api/auth/login', ['email' => 'viewer@flowsync.test', 'password' => 'password'])->assertOk();
        $this->postJson('/api/api-tokens', ['name' => 'V', 'abilities' => ['tms.read'], 'can_write' => false])->assertForbidden();
        $this->getJson('/api/api-tokens')->assertForbidden();
        $this->postJson('/api/auth/logout')->assertOk();
        $this->flushSession();

        // Editor (api.manage) can mint a read token without being a project member of anything.
        $this->postJson('/api/auth/login', ['email' => 'editor@flowsync.test', 'password' => 'password'])->assertOk();
        $this->postJson('/api/api-tokens', ['name' => 'E', 'abilities' => ['tms.read'], 'can_write' => false])->assertCreated();
    }

    public function test_a_user_who_may_not_write_anywhere_cannot_mint_a_write_token(): void
    {
        $admin = User::where('email', 'admin@flowsync.test')->firstOrFail();
        [$p] = $this->project('VIEW', $admin);
        $this->join($p, User::where('email', 'editor@flowsync.test')->firstOrFail(), 'viewer', $admin);

        $this->postJson('/api/auth/login', ['email' => 'editor@flowsync.test', 'password' => 'password'])->assertOk();

        $this->postJson('/api/api-tokens', ['name' => 'W', 'abilities' => ['tms.write'], 'can_write' => true])->assertStatus(422);
        $this->postJson('/api/api-tokens', ['name' => 'R', 'abilities' => ['tms.read'], 'can_write' => false])->assertCreated();
    }

    public function test_a_token_never_reaches_a_project_its_owner_is_not_part_of(): void
    {
        $admin = User::where('email', 'admin@flowsync.test')->firstOrFail();
        [$mine] = $this->project('MINE', $admin);
        [$theirs] = $this->project('THEIRS', $admin);
        $editor = User::where('email', 'editor@flowsync.test')->firstOrFail();
        $this->join($mine, $editor, 'developer', $admin);
        $taskId = $this->createTask($theirs, $this->issue());
        $token = $this->issue(['abilities' => ['tms.read', 'tms.write'], 'can_write' => true], 'editor@flowsync.test');

        foreach ([
            ['get', "/api/v1/projects/{$theirs->id}/tasks/{$taskId}", []],
            ['get', "/api/v1/projects/{$theirs->id}/tasks", []],
            ['put', "/api/v1/projects/{$theirs->id}/tasks/{$taskId}", ['title' => 'Nope']],
            ['post', "/api/v1/projects/{$theirs->id}/tasks", ['title' => 'Nope']],
            ['post', "/api/v1/projects/{$theirs->id}/tasks/{$taskId}/comments", ['comment' => 'Nope']],
        ] as [$method, $uri, $body]) {
            $response = $method === 'get'
                ? $this->getJson($uri, $this->bearer($token))
                : $this->{$method.'Json'}($uri, $body, $this->bearer($token));
            $this->assertContains($response->status(), [403, 404], "{$method} {$uri} answered {$response->status()}");
        }
        $this->assertSame('Task in THEIRS', Task::findOrFail($taskId)->title);
    }

    public function test_the_own_scope_limits_a_token_to_the_tasks_its_owner_may_edit(): void
    {
        $admin = User::where('email', 'admin@flowsync.test')->firstOrFail();
        $editor = User::where('email', 'editor@flowsync.test')->firstOrFail();
        [$p] = $this->project('OWN', $admin);
        ProjectRole::create(['name' => 'Contributor', 'slug' => 'contributor', 'is_system' => false, 'permissions' => ['projects.view', 'tasks.view_all', 'tasks.create', 'tasks.edit_own']]);
        $this->join($p, $editor, 'contributor', $admin);

        $adminToken = $this->issue();
        $mine = $this->createTask($p, $adminToken, ['assignee_id' => $editor->id]);
        $others = $this->createTask($p, $adminToken, ['assignee_id' => $admin->id]);
        $token = $this->issue(['abilities' => ['tms.read', 'tms.write'], 'can_write' => true], 'editor@flowsync.test');

        $this->putJson("/api/v1/projects/{$p->id}/tasks/{$mine}", ['title' => 'Mine'], $this->bearer($token))->assertOk();
        $this->putJson("/api/v1/projects/{$p->id}/tasks/{$others}", ['title' => 'Not mine'], $this->bearer($token))->assertForbidden();
    }

    // ------------------------------------------------------------ authentication boundaries

    public function test_a_bearer_token_is_not_accepted_by_the_session_api(): void
    {
        $admin = User::where('email', 'admin@flowsync.test')->firstOrFail();
        [$p] = $this->project('SESS', $admin);
        $token = $this->issue(['abilities' => ['tms.read', 'tms.write', 'hrms.view'], 'can_write' => true]);

        $this->getJson('/api/auth/me', $this->bearer($token))->assertUnauthorized();
        $this->getJson('/api/hrms/employees', $this->bearer($token))->assertUnauthorized();
        $this->postJson("/api/projects/{$p->id}/tasks", ['title' => 'x'], $this->bearer($token))->assertUnauthorized();
        $this->postJson('/api/hrms/payroll/runs/1/approve', [], $this->bearer($token))->assertUnauthorized();
    }

    // ------------------------------------------------------------ HRMS is read-only through tokens

    public function test_hrms_is_read_only_through_tokens_by_construction(): void
    {
        $seen = 0;
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            if (! str_contains($route->uri(), 'hrms') && ! str_contains($route->getActionName(), '\\Hrms\\')) {
                continue;
            }
            $seen++;

            $this->assertSame([], array_values(array_diff($route->methods(), ['GET', 'HEAD'])), "{$route->uri()} must be GET-only");
            $this->assertContains('hrms_read_only', $route->gatherMiddleware(), "{$route->uri()} must carry the hrms_read_only guard");
        }

        $this->assertGreaterThan(0, $seen, 'the guard must not pass vacuously');
    }

    public function test_a_writable_hrms_token_cannot_change_any_hrms_record(): void
    {
        $token = $this->issue(['abilities' => ['tms.read', 'tms.write', 'hrms.view'], 'can_write' => true]);

        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $status = $this->{$method.'Json'}('/api/v1/hrms/employees', ['name' => 'Injected'], $this->bearer($token))->status();
            $this->assertContains($status, [403, 404, 405], "{$method} answered {$status}");
        }
        $this->assertContains($this->deleteJson('/api/v1/hrms/employees/1', [], $this->bearer($token))->status(), [403, 404, 405]);
        $this->connectTenant('acme');
        $this->assertSame(0, Employee::query()->where('name', 'Injected')->count());
    }

    public function test_the_hrms_directory_through_a_token_shows_exactly_what_the_owner_sees_in_the_app(): void
    {
        $this->connectTenant('acme');
        $editor = User::where('email', 'editor@flowsync.test')->firstOrFail();
        $mine = Employee::create(['employee_code' => 'EMP-MX-1', 'name' => 'Editor Self', 'status' => EmployeeStatus::Active, 'user_id' => $editor->id]);
        Employee::create(['employee_code' => 'EMP-MX-2', 'name' => 'Someone Else', 'status' => EmployeeStatus::Active]);
        Employee::create(['employee_code' => 'EMP-MX-3', 'name' => 'Another Person', 'status' => EmployeeStatus::Active]);

        $token = $this->issue(['abilities' => ['hrms.view'], 'can_write' => false], 'editor@flowsync.test');
        $viaToken = $this->getJson('/api/v1/hrms/employees', $this->bearer($token));

        $this->postJson('/api/auth/login', ['email' => 'editor@flowsync.test', 'password' => 'password'])->assertOk();
        $viaApp = $this->getJson('/api/hrms/employees');
        $this->postJson('/api/auth/logout')->assertOk();

        $this->assertSame($viaApp->status(), $viaToken->status(), 'the token must not see more than the app shows this person');
        if ($viaToken->status() === 200) {
            $ids = fn ($r) => collect($r->json('employees'))->pluck('id')->sort()->values()->all();
            $this->assertSame($ids($viaApp), $ids($viaToken));
            $this->assertNotContains('EMP-MX-2', collect($viaToken->json('employees'))->pluck('employee_code')->all());
            $this->assertContains($mine->id, $ids($viaToken) ?: [$mine->id]);
        }
    }

    // ------------------------------------------------------------ TMS writes must not write HR data

    public function test_a_task_that_hr_links_is_read_only_for_a_token(): void
    {
        $admin = User::where('email', 'admin@flowsync.test')->firstOrFail();
        [$p] = $this->project('HRLINK', $admin);
        $token = $this->issue();
        $linkedToGoal = $this->createTask($p, $token);
        $linkedByHr = $this->createTask($p, $token);
        $plain = $this->createTask($p, $token);

        $employee = $this->employeeFor($admin);
        $goal = $this->goal($employee);
        GoalTaskLink::create(['goal_id' => $goal->id, 'task_id' => $linkedToGoal]);
        TaskLink::create(['employee_id' => $employee->id, 'task_id' => $linkedByHr, 'kind' => 'onboarding']);
        $before = $goal->fresh()->progress_evidence;

        foreach ([$linkedToGoal, $linkedByHr] as $taskId) {
            $other = $this->otherStatus($p, $taskId);
            foreach ([
                ['put', "/api/v1/projects/{$p->id}/tasks/{$taskId}", ['title' => 'Changed']],
                ['post', "/api/v1/projects/{$p->id}/tasks/{$taskId}/move", ['status_id' => $other]],
                ['post', "/api/v1/projects/{$p->id}/tasks/{$taskId}/comments", ['comment' => 'Changed']],
                ['post', "/api/v1/projects/{$p->id}/tasks/{$taskId}/work-logs", $this->log()],
            ] as [$method, $uri, $body]) {
                $this->{$method.'Json'}($uri, $body, $this->bearer($token))
                    ->assertForbidden()->assertJsonPath('code', 'hrms_linked_task_read_only');
            }
            $this->getJson("/api/v1/projects/{$p->id}/tasks/{$taskId}", $this->bearer($token))->assertOk();
        }

        $this->assertSame($before, $goal->fresh()->progress_evidence, 'HR goal evidence must not be touched through a token');

        // An ordinary task is unaffected.
        $this->putJson("/api/v1/projects/{$p->id}/tasks/{$plain}", ['title' => 'Fine'], $this->bearer($token))->assertOk();
    }

    public function test_time_logged_through_a_token_is_refused_while_hr_derives_attendance_from_work_logs(): void
    {
        $admin = User::where('email', 'admin@flowsync.test')->firstOrFail();
        [$p] = $this->project('ATT', $admin);
        $token = $this->issue();
        $taskId = $this->createTask($p, $token);

        $this->postJson("/api/v1/projects/{$p->id}/tasks/{$taskId}/work-logs", $this->log(), $this->bearer($token))->assertCreated();

        $settings = HrmsSetting::current();
        $settings->update(['attendance' => [...($settings->attendance ?? []), 'auto_derive_from_work_logs' => true]]);

        $this->postJson("/api/v1/projects/{$p->id}/tasks/{$taskId}/work-logs", $this->log(30, 600), $this->bearer($token))
            ->assertForbidden()->assertJsonPath('code', 'hrms_attendance_derivation_enabled');
    }

    // ------------------------------------------------------------ helpers

    private function issue(array $over = [], string $email = 'admin@flowsync.test'): string
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
        $plain = $this->postJson('/api/api-tokens', $over + [
            'name' => 'Agent',
            'abilities' => ['tms.read', 'tms.write'],
            'can_write' => true,
        ])->assertCreated()->json('plaintext');
        $this->postJson('/api/auth/logout')->assertOk();
        $this->flushSession();

        return $plain;
    }

    /** @return array<string, string> */
    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    /** @return array{Project, array<string, TaskStatus>} */
    private function project(string $key, User $lead): array
    {
        $this->connectTenant('acme');
        $ws = Workspace::create(['created_by' => $lead->id, 'name' => "WS {$key}", 'slug' => 'ws-'.strtolower($key).'-'.uniqid()]);
        $ws->members()->attach($lead->id, ['role' => 'owner', 'added_by' => $lead->id]);
        $project = Project::create(['workspace_id' => $ws->id, 'created_by' => $lead->id, 'lead_user_id' => $lead->id, 'name' => "Project {$key}", 'key' => $key]);
        $project->members()->attach($lead->id, ['project_role_id' => ProjectRole::where('slug', 'lead')->firstOrFail()->id, 'added_by' => $lead->id]);

        $statuses = [];
        foreach (config('task_statuses.statuses') as $s) {
            $statuses[$s['slug']] = TaskStatus::create([
                'project_id' => $project->id, 'name' => $s['name'], 'slug' => $s['slug'], 'category' => $s['category'],
                'position' => $s['position'], 'color' => $s['color'], 'is_done' => $s['is_done'], 'is_default' => $s['is_default'] ?? false,
            ]);
        }

        return [$project, $statuses];
    }

    private function join(Project $project, User $user, string $role, User $addedBy): void
    {
        $this->connectTenant('acme');
        $project->members()->attach($user->id, ['project_role_id' => ProjectRole::where('slug', $role)->firstOrFail()->id, 'added_by' => $addedBy->id]);
    }

    /** @param  array<string, mixed>  $extra */
    private function createTask(Project $project, string $token, array $extra = []): int
    {
        return (int) $this->postJson("/api/v1/projects/{$project->id}/tasks", ['title' => "Task in {$project->key}"] + $extra, $this->bearer($token))
            ->assertCreated()->json('task.id');
    }

    private function otherStatus(Project $project, int $taskId): int
    {
        $this->connectTenant('acme');

        return (int) TaskStatus::where('project_id', $project->id)->where('id', '!=', Task::findOrFail($taskId)->status_id)->where('is_done', false)->orderBy('position')->firstOrFail()->id;
    }

    /** @return array<string, string> */
    private function log(int $minutes = 30, int $endedMinutesAgo = 5): array
    {
        return ['description' => 'Work', 'started_at' => now()->subMinutes($minutes + $endedMinutesAgo)->toIso8601String(), 'ended_at' => now()->subMinutes($endedMinutesAgo)->toIso8601String()];
    }

    private function employeeFor(User $user): Employee
    {
        $this->connectTenant('acme');

        return Employee::firstOrCreate(['user_id' => $user->id], ['employee_code' => 'EMP-MX-A'.$user->id, 'name' => $user->name, 'status' => EmployeeStatus::Active]);
    }

    private function goal(Employee $employee): PerformanceGoal
    {
        $cycle = PerformanceCycle::firstOrCreate(['slug' => 'mx-2026'], ['name' => 'MX 2026', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31']);

        return PerformanceGoal::create([
            'cycle_id' => $cycle->id, 'employee_id' => $employee->id, 'title' => 'Evidence goal.', 'metric_type' => GoalMetricType::TaskCompletion,
            'target_value' => '10', 'status' => 'active', 'progress_percent' => '0.00', 'progress_source' => GoalProgressSource::Manual,
        ]);
    }
}
