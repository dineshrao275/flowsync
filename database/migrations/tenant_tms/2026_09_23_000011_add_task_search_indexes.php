<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Phase 7 index pass: FK columns on `tasks` were created without
        // dedicated indexes (only the two unique composites). These support
        // global search, dashboard, and report queries.
        Schema::table('tasks', function (Blueprint $table) {
            $table->index('workspace_id');
            $table->index('status_id');
            $table->index('assignee_id');
            $table->index('due_date');
            $table->index(['project_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'updated_at']);
            $table->dropIndex(['due_date']);
            $table->dropIndex(['assignee_id']);
            $table->dropIndex(['status_id']);
            $table->dropIndex(['workspace_id']);
        });
    }
};
