<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\SystemUser;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\TenantDatabaseManager;
use Database\Seeders\ScaleDataSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScaleDataSeederTest extends TestCase
{
    private string $tenantDir;

    private string $systemDb;

    private TenantDatabaseManager $dbm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantDir = sys_get_temp_dir().'/flowsync-scale-'.Str::random(8);
        $this->systemDb = $this->tenantDir.'/system.sqlite';
        File::makeDirectory($this->tenantDir, 0775, true);

        Config::set('tenancy.driver', 'isolated');
        Config::set('tenancy.system.connection', 'iso_system');
        Config::set('tenancy.tenant.driver', 'sqlite');
        Config::set('tenancy.tenant.db_path', $this->tenantDir);
        Config::set('database.connections.iso_system', $this->sqliteConfig($this->systemDb));

        Artisan::call('migrate', [
            '--database' => 'iso_system',
            '--path' => 'database/migrations/system',
            '--force' => true,
        ]);

        $this->dbm = app(TenantDatabaseManager::class);
        $this->dbm->connectSystem();

        app(ScaleDataSeeder::class)->run(
            tenants: 3,
            usersPerTenant: 10,
            workspacesPerTenant: 2,
            projectsPerWorkspace: 2,
            tasksPerProject: 25,
        );
    }

    protected function tearDown(): void
    {
        Config::set('tenancy.driver', 'isolated');
        Config::set('tenancy.system.connection', 'iso_system');
        Config::set('tenancy.tenant.driver', 'sqlite');
        Config::set('tenancy.tenant.db_path', database_path('tenants'));
        Config::offsetUnset('database.connections.iso_system');

        DB::purge('iso_system');
        DB::purge('tenant');
        DB::setDefaultConnection('sqlite');

        if (File::isDirectory($this->tenantDir)) {
            File::deleteDirectory($this->tenantDir);
        }

        parent::tearDown();
    }

    private function sqliteConfig(string $path): array
    {
        return [
            'driver' => 'sqlite',
            'url' => '',
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ];
    }

    public function test_creates_super_admin_and_exact_hierarchy_counts(): void
    {
        $superAdmin = SystemUser::where('is_super_admin', true)->first();
        $this->assertNotNull($superAdmin);

        $this->assertSame(3, Tenant::count());

        foreach (Tenant::all() as $tenant) {
            $counts = $this->dbm->using($tenant, fn () => [
                'users' => User::count(),
                'workspaces' => Workspace::count(),
                'projects' => Project::count(),
                'tasks' => Task::count(),
            ]);

            $this->assertSame(10, $counts['users']);
            $this->assertSame(2, $counts['workspaces']);
            $this->assertSame(4, $counts['projects']);
            $this->assertSame(100, $counts['tasks']);
        }
    }

    public function test_every_project_has_one_task_per_requested_count_and_statuses(): void
    {
        foreach (Tenant::all() as $tenant) {
            $this->dbm->using($tenant, function (): void {
                foreach (Project::all() as $project) {
                    $this->assertSame(25, $project->tasks()->count());
                    $this->assertSame(25, $project->last_task_sequence);
                    $this->assertSame(5, $project->statuses()->count());
                }
            });
        }
    }

    public function test_hierarchy_relationships_are_consistent(): void
    {
        foreach (Tenant::all() as $tenant) {
            $this->dbm->using($tenant, function (): void {
                foreach (Project::with('tasks')->get() as $project) {
                    foreach ($project->tasks as $task) {
                        $this->assertSame($project->workspace_id, $task->workspace_id);
                        $this->assertSame($project->id, $task->project_id);
                    }
                }
            });
        }
    }

    public function test_tasks_are_distributed_across_statuses_priorities_and_assignees(): void
    {
        $tenant = Tenant::orderBy('id')->first();

        $this->dbm->using($tenant, function (): void {
            $project = Project::with(['tasks', 'statuses'])->first();

            $statusIds = $project->tasks->pluck('status_id')->unique();
            $priorityIds = $project->tasks->pluck('priority_id')->unique();
            $assigneeIds = $project->tasks->pluck('assignee_id')->filter()->unique();

            $this->assertGreaterThanOrEqual(3, $statusIds->count());
            $this->assertGreaterThanOrEqual(3, $priorityIds->count());
            $this->assertGreaterThanOrEqual(3, $assigneeIds->count());
            $this->assertSame(25, $project->tasks->count());

            $this->assertTrue($project->tasks->whereNull('assignee_id')->isNotEmpty());
            $this->assertTrue($project->tasks->whereNotIn('assignee_id', $assigneeIds)->whereNotNull('assignee_id')->isEmpty());

            $doneStatusIds = $project->statuses->where('is_done', true)->pluck('id');
            foreach ($project->tasks->whereIn('status_id', $doneStatusIds) as $done) {
                $this->assertNotNull($done->completed_at);
            }
            foreach ($project->tasks->whereNotIn('status_id', $doneStatusIds) as $open) {
                $this->assertNull($open->completed_at);
            }

            $keys = $project->tasks->pluck('key');
            $this->assertSame(25, $keys->unique()->count());
        });
    }

    public function test_tenant_isolation_across_users_workspaces_projects_and_tasks(): void
    {
        $tenants = Tenant::orderBy('id')->take(2)->get();
        [$first, $second] = $tenants;

        // Phase 13: one database per tenant — ids restart from 1 in every tenant
        // DB, so cross-tenant uniqueness is proven via email content, not numeric ids.
        $firstUserEmails = $this->dbm->using($first, fn () => User::pluck('email'));
        $secondUserEmails = $this->dbm->using($second, fn () => User::pluck('email'));
        $this->assertTrue($firstUserEmails->intersect($secondUserEmails)->isEmpty());

        $firstUsers = $this->dbm->using($first, fn () => User::pluck('id'));

        $firstWsIds = $this->dbm->using($first, fn () => Workspace::pluck('id'));
        $secondWsIds = $this->dbm->using($second, fn () => Workspace::pluck('id'));

        $firstMemberEmails = $this->dbm->using(
            $first,
            fn () => DB::table('workspace_members')
                ->join('users', 'users.id', '=', 'workspace_members.user_id')
                ->whereIn('workspace_members.workspace_id', $firstWsIds)
                ->pluck('users.email')
        );
        $this->assertTrue($firstMemberEmails->diff($firstUserEmails)->isEmpty());

        $secondMemberEmails = $this->dbm->using(
            $second,
            fn () => DB::table('workspace_members')
                ->join('users', 'users.id', '=', 'workspace_members.user_id')
                ->whereIn('workspace_members.workspace_id', $secondWsIds)
                ->pluck('users.email')
        );
        $this->assertTrue($secondMemberEmails->diff($secondUserEmails)->isEmpty());
        $this->assertTrue($firstMemberEmails->intersect($secondMemberEmails)->isEmpty());

        $firstProjectIds = $this->dbm->using($first, fn () => Project::pluck('id'));
        $secondProjectIds = $this->dbm->using($second, fn () => Project::pluck('id'));

        $firstProjectMemberEmails = $this->dbm->using(
            $first,
            fn () => DB::table('project_members')
                ->join('users', 'users.id', '=', 'project_members.user_id')
                ->whereIn('project_members.project_id', $firstProjectIds)
                ->pluck('users.email')
        );
        $secondProjectMemberEmails = $this->dbm->using(
            $second,
            fn () => DB::table('project_members')
                ->join('users', 'users.id', '=', 'project_members.user_id')
                ->whereIn('project_members.project_id', $secondProjectIds)
                ->pluck('users.email')
        );
        $this->assertTrue($firstProjectMemberEmails->diff($firstUserEmails)->isEmpty());
        $this->assertTrue($firstProjectMemberEmails->intersect($secondProjectMemberEmails)->isEmpty());

        $firstTasks = $this->dbm->using(
            $first,
            fn () => Task::whereNotNull('assignee_id')->pluck('assignee_id')
        );
        $this->assertTrue($firstTasks->diff($firstUsers)->isEmpty());

        $this->dbm->using($first, function () use ($firstUsers): void {
            foreach (DB::table('notifications')->get() as $notification) {
                $this->assertContains($notification->user_id, $firstUsers->all());
                $this->assertContains($notification->actor_id, $firstUsers->all());
            }
        });
    }

    public function test_range_specs_are_honoured_and_reruns_stay_additive(): void
    {
        // setUp seeded 2 workspaces x 2 projects; a wider fixed spec adds the
        // missing rows without duplicating tasks in the existing projects.
        app(ScaleDataSeeder::class)->run(
            tenants: 3,
            usersPerTenant: 10,
            workspacesPerTenant: [3, 3],
            projectsPerWorkspace: [2, 2],
            tasksPerProject: 25,
        );

        foreach (Tenant::all() as $tenant) {
            $this->dbm->using($tenant, function (): void {
                $this->assertSame(10, User::count());
                $this->assertSame(3, Workspace::count());
                $this->assertSame(6, Project::count());
                $this->assertSame(6 * 25, Task::count());

                foreach (Project::all() as $project) {
                    $this->assertSame(25, $project->tasks()->count());
                }
            });
        }
    }

    public function test_each_tenant_lands_inside_the_requested_range(): void
    {
        app(ScaleDataSeeder::class)->run(
            tenants: 3,
            usersPerTenant: 10,
            workspacesPerTenant: [2, 4],
            projectsPerWorkspace: [2, 3],
            tasksPerProject: 25,
            seed: 1234,
        );

        foreach (Tenant::all() as $tenant) {
            $this->dbm->using($tenant, function (): void {
                $this->assertGreaterThanOrEqual(2, Workspace::count());
                $this->assertLessThanOrEqual(4, Workspace::count());

                // Whatever shape was drawn, the task invariant always holds.
                $this->assertSame(Project::count() * 25, Task::count());
            });
        }
    }

    public function test_exactly_one_hundred_tasks_per_default_request(): void
    {
        $tenant = Tenant::orderBy('id')->first();
        $this->dbm->using($tenant, fn () => DB::table('tasks')->delete());

        app(ScaleDataSeeder::class)->run(tenants: 1, usersPerTenant: 10, workspacesPerTenant: 1, projectsPerWorkspace: 1, tasksPerProject: 100);

        $this->dbm->using($tenant, function (): void {
            $project = Project::first();
            $this->assertSame(100, $project->tasks()->count());
            $this->assertSame(100, $project->last_task_sequence);
            $this->assertSame(100, Task::count());
        });
    }
}
