<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F5.1/F5.3 — HR events creating work: "when <HR event>, create these tasks in <project>".
 * Lives in the integration set: it needs both the HRMS tables (employees) and the TMS tables (projects).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_automation_rules')) {
            Schema::create('hr_automation_rules', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('trigger', 60);
                $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
                $table->json('tasks');
                $table->boolean('is_active')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedInteger('run_count')->default(0);
                $table->timestamp('last_run_at')->nullable();
                $table->timestamps();

                $table->index(['trigger', 'is_active']);
            });
        }

        if (! Schema::hasTable('hr_automation_runs')) {
            Schema::create('hr_automation_runs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('rule_id')->constrained('hr_automation_rules')->cascadeOnDelete();
                $table->string('event_uuid', 40);
                $table->unsignedBigInteger('employee_id')->nullable();
                $table->string('status', 12)->default('success'); // success | failed | skipped
                $table->string('summary', 500)->nullable();
                $table->timestamp('created_at')->useCurrent();

                // One run per rule per event: a redelivered event never creates the tasks twice.
                $table->unique(['rule_id', 'event_uuid']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_automation_runs');
        Schema::dropIfExists('hr_automation_rules');
    }
};
