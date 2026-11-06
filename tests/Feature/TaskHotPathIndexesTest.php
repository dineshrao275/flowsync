<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** The migration's outcome, not its exit status (see AGENTS.md on repair-safe guards). */
class TaskHotPathIndexesTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    public function test_the_partial_indexes_exist_with_the_expected_columns_and_predicates(): void
    {
        $this->loginAdmin();

        $indexes = collect(Schema::getIndexes('tasks'))->keyBy('name');

        $this->assertSame(['project_id', 'status_id', 'position'], $indexes['tasks_project_status_position_idx']['columns']);
        $this->assertSame(['assignee_id', 'due_date', 'id'], $indexes['tasks_assignee_open_due_idx']['columns']);

        $sql = strtolower((string) DB::table('sqlite_master')->where('name', 'tasks_assignee_open_due_idx')->value('sql'));
        $this->assertStringContainsString('completed_at', $sql);
        $this->assertStringContainsString('archived_at', $sql);
    }

    public function test_the_migration_can_be_rolled_back_and_reapplied(): void
    {
        $this->loginAdmin();
        $migration = require base_path('database/migrations/tenant_tms/2026_12_16_000066_add_task_board_and_my_work_indexes.php');

        $migration->down();
        $this->assertFalse(Schema::hasIndex('tasks', 'tasks_assignee_open_due_idx'));

        $migration->up();
        $migration->up(); // repair run: no error, no duplicate
        $this->assertTrue(Schema::hasIndex('tasks', 'tasks_assignee_open_due_idx'));
        $this->assertTrue(Schema::hasIndex('tasks', 'tasks_project_status_position_idx'));
    }
}
