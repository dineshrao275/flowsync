<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hot FK lookups that fell through the Phase 0-4 creation migrations:
        // composited PKs/uniques cover the leftmost column only.

        Schema::table('projects', function (Blueprint $table) {
            $table->index('workspace_id', 'projects_workspace_id_index');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->index('parent_id', 'tasks_parent_id_index');
        });

        Schema::table('task_label', function (Blueprint $table) {
            // Label-id lookups (global search `label_id` filter, label clouds).
            $table->index('label_id', 'task_label_label_id_index');
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->index(['task_id', 'parent_id'], 'comments_task_parent_index');
            $table->index('user_id', 'comments_user_id_index');
        });

        Schema::table('attachments', function (Blueprint $table) {
            $table->index('task_id', 'attachments_task_id_index');
            $table->index('user_id', 'attachments_user_id_index');
        });

        Schema::table('work_logs', function (Blueprint $table) {
            $table->index(['task_id', 'started_at'], 'work_logs_task_started_index');
            $table->index('user_id', 'work_logs_user_id_index');
        });

        Schema::table('task_dependencies', function (Blueprint $table) {
            // The `blocks` direction queries on depends_on_task_id (not leftmost).
            $table->index('depends_on_task_id', 'task_dependencies_depends_on_index');
        });

        // Pivot lookups by the non-leftmost column.
        Schema::table('role_user', function (Blueprint $table) {
            $table->index('user_id', 'role_user_user_id_index');
        });

        Schema::table('permission_role', function (Blueprint $table) {
            $table->index('role_id', 'permission_role_role_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('permission_role', function (Blueprint $table) {
            $table->dropIndex('permission_role_role_id_index');
        });

        Schema::table('role_user', function (Blueprint $table) {
            $table->dropIndex('role_user_user_id_index');
        });

        Schema::table('task_dependencies', function (Blueprint $table) {
            $table->dropIndex('task_dependencies_depends_on_index');
        });

        Schema::table('work_logs', function (Blueprint $table) {
            $table->dropIndex('work_logs_user_id_index');
            $table->dropIndex('work_logs_task_started_index');
        });

        Schema::table('attachments', function (Blueprint $table) {
            $table->dropIndex('attachments_user_id_index');
            $table->dropIndex('attachments_task_id_index');
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->dropIndex('comments_user_id_index');
            $table->dropIndex('comments_task_parent_index');
        });

        Schema::table('task_label', function (Blueprint $table) {
            $table->dropIndex('task_label_label_id_index');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_parent_id_index');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex('projects_workspace_id_index');
        });
    }
};