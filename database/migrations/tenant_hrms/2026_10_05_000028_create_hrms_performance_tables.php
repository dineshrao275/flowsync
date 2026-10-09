<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRMS/P12.1 — the performance management tables.
 *
 * Eight repair-safe tables (`hasTable` guards — `tenants:provision` re-runs
 * migrations that failed partway): cycles own goals, check-ins, feedback
 * and review summaries; goals link tasks as evidence; one-on-ones stand
 * outside cycles (a conversation is not a review period).
 *
 * Deliberately absent: any `overall_score` — ratings are 1-5 per dimension,
 * entered by humans, and the engine this phase builds reads counts, never
 * writes a score. Owned rows cascade with their cycle, employee, or goal;
 * author and verifier links null so the record outlives the login.
 *
 * Numbered `000028`: the plan's `000025` is taken (payroll settings), like
 * `000024` before it — pre-assigned numbers are advisory once follow-ups
 * spend them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createPerformanceCycles();
        $this->createPerformanceGoals();
        $this->createGoalTaskLinks();
        $this->createCheckIns();
        $this->createOneOnOnes();
        $this->createFeedbackRequests();
        $this->createFeedbackResponses();
        $this->createReviewSummaries();
    }

    public function down(): void
    {
        Schema::dropIfExists('review_summaries');
        Schema::dropIfExists('feedback_responses');
        Schema::dropIfExists('feedback_requests');
        Schema::dropIfExists('one_on_ones');
        Schema::dropIfExists('check_ins');
        Schema::dropIfExists('goal_task_links');
        Schema::dropIfExists('performance_goals');
        Schema::dropIfExists('performance_cycles');
    }

    private function createPerformanceCycles(): void
    {
        if (Schema::hasTable('performance_cycles')) {
            return;
        }

        Schema::create('performance_cycles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->enum('stage', ['goal_setting', 'check_in', 'self_review', 'manager_review', 'calibration', 'completed'])
                ->default('goal_setting');
            $table->enum('anonymity', ['none', 'reviewer', 'peer'])->default('none');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    private function createPerformanceGoals(): void
    {
        if (Schema::hasTable('performance_goals')) {
            return;
        }

        Schema::create('performance_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('performance_cycles')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->enum('metric_type', ['none', 'task_completion', 'worklog_hours', 'manual'])->default('none');
            $table->decimal('target_value', 12, 2)->nullable();
            $table->decimal('weight', 5, 2)->default(100);
            $table->date('due_date')->nullable();
            $table->enum('status', ['draft', 'active', 'achieved', 'missed', 'cancelled'])->default('draft');
            $table->decimal('progress_percent', 5, 2)->default(0);
            $table->enum('progress_source', ['auto', 'manual'])->default('manual');
            $table->json('progress_evidence')->nullable();
            $table->timestamp('achieved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['cycle_id', 'employee_id']);
        });
    }

    private function createGoalTaskLinks(): void
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

    private function createCheckIns(): void
    {
        if (Schema::hasTable('check_ins')) {
            return;
        }

        Schema::create('check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('performance_cycles')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->text('body');
            $table->enum('mood', ['great', 'good', 'ok', 'low'])->nullable();
            $table->text('blockers')->nullable();
            $table->boolean('needs_support')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index(['cycle_id', 'created_at']);
        });
    }

    private function createOneOnOnes(): void
    {
        if (Schema::hasTable('one_on_ones')) {
            return;
        }

        Schema::create('one_on_ones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('manager_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('scheduled_at')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->text('agenda')->nullable();
            $table->text('notes')->nullable();
            $table->text('follow_up')->nullable();
            $table->json('action_items')->nullable();
            $table->enum('status', ['scheduled', 'held', 'cancelled'])->default('scheduled');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    private function createFeedbackRequests(): void
    {
        if (Schema::hasTable('feedback_requests')) {
            return;
        }

        Schema::create('feedback_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('performance_cycles')->cascadeOnDelete();
            $table->foreignId('from_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('to_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->enum('relation', ['manager', 'peer', 'direct_report'])->default('peer');
            $table->enum('status', ['pending', 'submitted', 'declined'])->default('pending');
            $table->date('due_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['cycle_id', 'to_employee_id']);
        });
    }

    private function createFeedbackResponses(): void
    {
        if (Schema::hasTable('feedback_responses')) {
            return;
        }

        Schema::create('feedback_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feedback_request_id')->constrained('feedback_requests')->cascadeOnDelete();
            $table->foreignId('from_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('body')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['feedback_request_id', 'from_employee_id']);
        });
    }

    private function createReviewSummaries(): void
    {
        if (Schema::hasTable('review_summaries')) {
            return;
        }

        Schema::create('review_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('performance_cycles')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->unsignedTinyInteger('self_rating')->nullable();
            $table->unsignedTinyInteger('manager_rating')->nullable();
            $table->text('strengths')->nullable();
            $table->text('improvements')->nullable();
            $table->text('manager_comments')->nullable();
            $table->json('evidence_snapshot')->nullable();
            $table->enum('visibility_to_employee', ['hidden', 'shared'])->default('hidden');
            $table->enum('status', ['draft', 'calibrating', 'final', 'acknowledged'])->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('manager_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->unique(['cycle_id', 'employee_id']);
        });
    }
};
