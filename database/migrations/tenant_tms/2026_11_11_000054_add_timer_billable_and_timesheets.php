<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F3.3 — billable time and weekly timesheets.
 *
 *  - `work_logs.billable`: whether the time is billable (default yes, so existing logs keep counting).
 *    A running timer is simply a log with no `ended_at`, so no new column is needed for it.
 *  - `timesheets`: one row per user and ISO week (Monday start). `status` open → submitted →
 *    approved | rejected; a submitted or approved week locks that user's logs in it.
 *
 * Plain columns (no FK on work_logs) so the sqlite fast-path never rebuilds the table. Repair-safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('work_logs') && ! Schema::hasColumn('work_logs', 'billable')) {
            Schema::table('work_logs', function (Blueprint $table) {
                $table->boolean('billable')->default(true);
            });
        }

        if (! Schema::hasTable('timesheets')) {
            Schema::create('timesheets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->date('week_start');
                $table->string('status', 16)->default('open');
                $table->unsignedInteger('total_minutes')->default(0);
                $table->unsignedInteger('billable_minutes')->default(0);
                $table->timestamp('submitted_at')->nullable();
                $table->unsignedBigInteger('decided_by')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->string('decision_note', 500)->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'week_start']);
                $table->index(['status', 'week_start']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheets');

        if (Schema::hasTable('work_logs') && Schema::hasColumn('work_logs', 'billable')) {
            Schema::table('work_logs', function (Blueprint $table) {
                $table->dropColumn('billable');
            });
        }
    }
};
