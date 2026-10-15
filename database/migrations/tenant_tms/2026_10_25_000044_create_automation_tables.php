<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('automation_rules')) {
            Schema::create('automation_rules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
                $table->string('name');
                $table->boolean('is_active')->default(true);
                $table->string('trigger', 40);          // a task event type
                $table->json('conditions')->nullable(); // [{field, op, value}]
                $table->json('actions');                // [{type, ...config}]
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedInteger('run_count')->default(0);
                $table->timestamp('last_run_at')->nullable();
                $table->timestamps();

                $table->index(['project_id', 'trigger', 'is_active']);
            });
        }

        if (! Schema::hasTable('automation_runs')) {
            Schema::create('automation_runs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('rule_id')->constrained('automation_rules')->cascadeOnDelete();
                $table->uuid('event_uuid');
                $table->unsignedBigInteger('task_id')->nullable()->index();
                $table->string('status', 16);           // success | skipped | failed
                $table->string('summary', 500)->nullable();
                $table->timestamps();

                // Idempotency: an event runs a rule at most once.
                $table->unique(['rule_id', 'event_uuid']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_rules');
    }
};
