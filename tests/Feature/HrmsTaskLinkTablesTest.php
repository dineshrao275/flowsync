<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P20.1 — the bridge between people and work.
 *
 * The isolation trait migrates every tenant, so this asserts the
 * migration's own contract: the link table with no `tenant_id`, one row
 * per (employee, task, kind), the kind enum enforced by the database, and
 * the nullable `tasks.hrms_employee_id` affordance — plus that a second
 * run is a no-op (provisioning re-runs migrations that failed partway).
 */
class HrmsTaskLinkTablesTest extends TestCase
{
    use IsolatesDatabase;

    public function test_the_table_exists_with_no_tenant_id(): void
    {
        $this->assertTrue(Schema::hasTable('hrms_task_links'));
        $this->assertFalse(Schema::hasColumn('hrms_task_links', 'tenant_id'));
    }

    public function test_the_link_grain_is_unique_per_kind(): void
    {
        $employee = $this->employee();
        $task = $this->task();

        DB::table('hrms_task_links')->insert([
            'employee_id' => $employee->id, 'task_id' => $task->id, 'kind' => 'goal',
        ]);

        $this->expectException(QueryException::class);

        DB::table('hrms_task_links')->insert([
            'employee_id' => $employee->id, 'task_id' => $task->id, 'kind' => 'goal',
        ]);
    }

    public function test_the_same_pair_links_again_under_another_kind(): void
    {
        $employee = $this->employee();
        $task = $this->task();

        DB::table('hrms_task_links')->insert([
            'employee_id' => $employee->id, 'task_id' => $task->id, 'kind' => 'goal',
        ]);
        DB::table('hrms_task_links')->insert([
            'employee_id' => $employee->id, 'task_id' => $task->id, 'kind' => 'onboarding',
        ]);

        $this->assertSame(2, DB::table('hrms_task_links')->count());
    }

    public function test_the_kind_enum_is_enforced_by_the_database(): void
    {
        $this->expectException(QueryException::class);

        DB::table('hrms_task_links')->insert([
            'employee_id' => $this->employee()->id, 'task_id' => $this->task()->id, 'kind' => 'karma',
        ]);
    }

    public function test_tasks_carry_a_nullable_hr_owned_person(): void
    {
        $this->assertTrue(Schema::hasColumn('tasks', 'hrms_employee_id'));

        $task = $this->task();
        $this->assertNull($task->hrms_employee_id);

        $task->update(['hrms_employee_id' => $this->employee()->id]);

        $this->assertNotNull($task->fresh()->hrms_employee_id);
    }

    public function test_a_second_migration_run_is_a_no_op(): void
    {
        $migration = require database_path('migrations/tenant_hrms/2026_10_11_000034_create_hrms_task_links_table.php');
        $migration->up();

        $this->assertTrue(Schema::hasTable('hrms_task_links'));
        $this->assertTrue(Schema::hasColumn('tasks', 'hrms_employee_id'));
    }

    // ------------------------------------------------------------ helpers

    private function employee(): Employee
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        return Employee::create([
            'employee_code' => "EMP-LINK-{$sequence}",
            'name' => "Link Employee {$sequence}",
            'status' => EmployeeStatus::Active,
        ]);
    }

    private function task(): Task
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        $admin = User::where('email', 'admin@flowsync.test')->firstOrFail();

        $workspace = Workspace::create([
            'created_by' => $admin->id,
            'name' => "Links {$sequence}",
            'slug' => "links-{$sequence}",
        ]);

        $project = Project::create([
            'workspace_id' => $workspace->id,
            'created_by' => $admin->id,
            'lead_user_id' => $admin->id,
            'name' => "Links {$sequence}",
            'key' => "LK{$sequence}",
        ]);

        foreach (config('task_statuses.statuses') as $status) {
            $project->statuses()->create([
                'name' => $status['name'],
                'slug' => $status['slug'],
                'category' => $status['category'],
                'color' => $status['color'] ?? null,
                'position' => $status['position'] ?? 1,
                'is_default' => $status['is_default'] ?? false,
                'is_done' => $status['is_done'] ?? false,
            ]);
        }

        $project->increment('last_task_sequence');

        return Task::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'created_by' => $admin->id,
            'key' => $project->key.'-'.$project->last_task_sequence,
            'sequence' => $project->last_task_sequence,
            'title' => "Link task {$sequence}",
            'status_id' => $project->statuses()->where('is_default', true)->first()->id,
            'position' => 1,
        ]);
    }
}
