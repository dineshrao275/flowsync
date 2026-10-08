<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRMS/P20.3 — what a task link was raised from.
 *
 * P20.1's bridge names (employee, task, kind) but not the checklist item
 * the task was converted from — so a sync ("is THIS item's task done?")
 * could only guess among the employee's links. `source_type` names the
 * item table's side (`onboarding_task`, `offboarding_task`) and
 * `source_id` the row; both nullable because hand-filed links (goal
 * evidence, the P20.2 panel) are raised from nothing. Repair-safe and
 * additive: existing rows keep nulls, which read as "no source".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hrms_task_links', function (Blueprint $table) {
            if (! Schema::hasColumn('hrms_task_links', 'source_type')) {
                $table->string('source_type', 32)->nullable();
            }

            if (! Schema::hasColumn('hrms_task_links', 'source_id')) {
                $table->unsignedBigInteger('source_id')->nullable();
            }
        });

        Schema::table('hrms_task_links', function (Blueprint $table) {
            $indexes = collect(Schema::getIndexes('hrms_task_links'))->pluck('name')->all();

            if (! in_array('hrms_task_links_source_type_source_id_index', $indexes, true)) {
                $table->index(['source_type', 'source_id']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('hrms_task_links', function (Blueprint $table) {
            $table->dropIndex(['source_type', 'source_id']);
        });

        Schema::table('hrms_task_links', function (Blueprint $table) {
            $table->dropColumn(['source_type', 'source_id']);
        });
    }
};
