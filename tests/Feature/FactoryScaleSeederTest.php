<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\TenantUserRouting;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WorkLog;
use App\Models\Workspace;
use App\Support\TenantDatabaseManager;
use Database\Seeders\FactoryScaleSeeder;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * Factory scale seeding, end to end at tiny volume.
 *
 * Proves the wipe removes a pre-existing tenant (row, database file and
 * all), the reseed rebuilds demo tenants plus one scale tenant through
 * factories alone, and every related row points at a real record: members
 * resolve, statuses belong to their project, task keys are unique, and
 * assignees sit on the project.
 */
class FactoryScaleSeederTest extends TestCase
{
    use IsolatesDatabase;

    public function test_wipe_and_factory_reseed_keep_every_relation_honest(): void
    {
        $junk = Tenant::create(['name' => 'Junk', 'slug' => 'junk']);
        $junkPath = app(TenantDatabaseManager::class)->tenantDatabasePath($junk);
        @file_put_contents($junkPath, '');

        app(FactoryScaleSeeder::class)->run(
            tenants: 1,
            usersPerTenant: 2,
            workspacesPerTenant: 1,
            projectsPerWorkspace: 1,
            tasksPerProject: 5,
        );

        // The junk tenant is gone — row, file and all.
        $this->assertNull(Tenant::where('slug', 'junk')->first());
        $this->assertFileDoesNotExist($junkPath);

        // Demo tenants plus the one scale tenant, all serviceable.
        $this->assertSame(
            ['acme', 'globex', 'tenant-001'],
            Tenant::pluck('slug')->sort()->values()->all(),
        );

        foreach (Tenant::all() as $tenant) {
            $this->assertTrue($tenant->isServiceable());
        }

        // Every tenant user routes to a login.
        $this->connectTenant('tenant-001');
        $this->assertSame(2, User::count());

        foreach (User::all() as $user) {
            $this->assertTrue(
                TenantUserRouting::where('email', $user->email)->where('user_id', $user->id)->exists(),
                "No routing row for {$user->email}",
            );
        }

        $workspace = Workspace::firstOrFail();
        $this->assertSame(2, $workspace->members()->count());

        $project = Project::firstOrFail();
        $this->assertSame(5, $project->statuses()->count());
        $this->assertSame(2, $project->members()->count());

        $tasks = Task::orderBy('id')->get();
        $this->assertCount(5, $tasks);
        $this->assertSame([1, 2, 3, 4, 5], $tasks->pluck('sequence')->all());
        $this->assertCount(5, $tasks->pluck('key')->unique());

        $memberIds = $project->members()->pluck('user_id')->all();

        foreach ($tasks as $task) {
            $this->assertSame($project->id, $task->status->project_id);
            $this->assertTrue($task->assignee_id === null || in_array($task->assignee_id, $memberIds, true));
        }

        // Related rows land on real records: one comment (offset 0), one
        // work log (offset 4), one assigned-notification for the worker.
        $this->assertSame(1, Comment::count());
        $this->assertSame(1, WorkLog::count());
        $this->assertSame($tasks[0]->id, Comment::first()->task_id);
        $this->assertSame($tasks[4]->id, WorkLog::first()->task_id);
        $this->assertTrue(UserNotification::where('type', 'task.assigned')->exists());
    }
}
