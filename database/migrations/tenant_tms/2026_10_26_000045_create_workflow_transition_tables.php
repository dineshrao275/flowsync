<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow rules (P3.1/P3.2): an optional allow-list of status transitions per project, entry
 * requirements per status, and a status history (the basis for cycle/lead-time reports).
 * A project with `enforce_workflow` off behaves exactly as before: any status to any status.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('projects', 'enforce_workflow')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->boolean('enforce_workflow')->default(false);
            });
        }

        if (! Schema::hasColumn('task_statuses', 'entry_rules')) {
            Schema::table('task_statuses', function (Blueprint $table) {
                $table->json('entry_rules')->nullable(); // ["assignee","due_date",...] a task must satisfy to enter
            });
        }

        if (! Schema::hasTable('status_transitions')) {
            Schema::create('status_transitions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
                $table->foreignId('from_status_id')->nullable()->constrained('task_statuses')->cascadeOnDelete(); // null = from any status
                $table->foreignId('to_status_id')->constrained('task_statuses')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['project_id', 'from_status_id', 'to_status_id'], 'status_transitions_unique');
            });
        }

        if (! Schema::hasTable('task_status_history')) {
            Schema::create('task_status_history', function (Blueprint $table) {
                $table->id();
                $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
                $table->unsignedBigInteger('from_status_id')->nullable();
                $table->unsignedBigInteger('to_status_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->timestamp('changed_at')->useCurrent();

                $table->index(['task_id', 'id']);
                $table->index('changed_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_status_history');
        Schema::dropIfExists('status_transitions');
    }
};
