<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRMS/P18.1 — the scheduled report table.
 *
 * One row per digest: what to build (`definition` names sections and
 * filters), how often, and who gets it (user ids and/or role slugs —
 * roles resolve at send time, so a digest keeps following the role as
 * people join and leave it). `next_run_at` is the only scheduling state:
 * the command sends what is due and advances it, so a sent digest never
 * re-sends by construction rather than by a sent-flag ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hrms_report_schedules')) {
            return;
        }

        Schema::create('hrms_report_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->json('definition')->nullable();
            $table->enum('cadence', ['daily', 'weekly', 'monthly', 'quarterly'])->default('weekly');
            $table->json('recipients')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrms_report_schedules');
    }
};
