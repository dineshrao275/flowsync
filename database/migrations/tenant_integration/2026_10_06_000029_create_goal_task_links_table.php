<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-product bridge — a performance goal measured by linked project work.
 *
 * This table was born inside the HRMS performance migration
 * (`tenant_hrms/2026_10_05_000028_create_hrms_performance_tables`), but its
 * `task_id` is a real FK into the TMS `tasks` table, so it belongs to neither
 * product alone. It now runs with the integration set, only when a tenant has
 * both TMS and HRMS — the same rule as `hrms_task_links`.
 *
 * Repair-safe: created only when absent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('goal_task_links')) {
            return;
        }

        Schema::create('goal_task_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained('performance_goals')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['goal_id', 'task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goal_task_links');
    }
};
