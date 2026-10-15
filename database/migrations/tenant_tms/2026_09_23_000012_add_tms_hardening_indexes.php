<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TMS hardening indexes (the TMS half of the former tenant-hardening pass).
 *
 * Hot FK lookups that fell through the Phase 0-4 creation migrations: composite
 * PKs/uniques cover the leftmost column only. Runs only for tenants with the TMS
 * product, after the TMS tables exist. Every index is existence-guarded so a
 * repair re-run (`tenants:provision`) is a no-op on tenants that already carry
 * these indexes from the pre-split combined migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addIndex('projects', 'projects_workspace_id_index', ['workspace_id']);
        $this->addIndex('tasks', 'tasks_parent_id_index', ['parent_id']);
        $this->addIndex('task_label', 'task_label_label_id_index', ['label_id']);
        $this->addIndex('comments', 'comments_task_parent_index', ['task_id', 'parent_id']);
        $this->addIndex('comments', 'comments_user_id_index', ['user_id']);
        $this->addIndex('attachments', 'attachments_task_id_index', ['task_id']);
        $this->addIndex('attachments', 'attachments_user_id_index', ['user_id']);
        $this->addIndex('work_logs', 'work_logs_task_started_index', ['task_id', 'started_at']);
        $this->addIndex('work_logs', 'work_logs_user_id_index', ['user_id']);
        $this->addIndex('task_dependencies', 'task_dependencies_depends_on_index', ['depends_on_task_id']);
    }

    public function down(): void
    {
        $this->dropIndex('task_dependencies', 'task_dependencies_depends_on_index');
        $this->dropIndex('work_logs', 'work_logs_user_id_index');
        $this->dropIndex('work_logs', 'work_logs_task_started_index');
        $this->dropIndex('attachments', 'attachments_user_id_index');
        $this->dropIndex('attachments', 'attachments_task_id_index');
        $this->dropIndex('comments', 'comments_user_id_index');
        $this->dropIndex('comments', 'comments_task_parent_index');
        $this->dropIndex('task_label', 'task_label_label_id_index');
        $this->dropIndex('tasks', 'tasks_parent_id_index');
        $this->dropIndex('projects', 'projects_workspace_id_index');
    }

    /**
     * @param  list<string>  $columns
     */
    private function addIndex(string $table, string $name, array $columns): void
    {
        if (! Schema::hasTable($table) || $this->indexExists($table, $name)) {
            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $table) use ($name, $columns) {
            $table->index($columns, $name);
        });
    }

    private function dropIndex(string $table, string $name): void
    {
        if (! Schema::hasTable($table) || ! $this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($name) {
            $table->dropIndex($name);
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? '') === $indexName) {
                return true;
            }
        }

        return false;
    }
};
