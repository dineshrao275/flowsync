<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** F4.7 — task SLA policies (resolve within N hours, optionally per priority) and the breaches they record. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sla_policies')) {
            Schema::create('sla_policies', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
                $table->string('name', 120);
                $table->foreignId('priority_id')->nullable()->constrained('priorities')->nullOnDelete();
                $table->unsignedInteger('target_hours');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['project_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('sla_breaches')) {
            Schema::create('sla_breaches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sla_policy_id')->constrained('sla_policies')->cascadeOnDelete();
                $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
                $table->timestamp('breached_at');
                $table->timestamps();

                $table->unique(['sla_policy_id', 'task_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_breaches');
        Schema::dropIfExists('sla_policies');
    }
};
