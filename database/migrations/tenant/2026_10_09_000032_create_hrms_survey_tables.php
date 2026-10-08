<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRMS/P16.1 — the engagement survey tables.
 *
 * Six repair-safe tables (`hasTable` guards — `tenants:provision` re-runs
 * migrations that failed partway): templates own questions; campaigns open
 * a template to an audience; responses carry one submission each;
 * answers hang off responses; results snapshot the aggregates per
 * question so reads never recompute over raw rows.
 *
 * Anonymity is structural, not procedural: an anonymous campaign stores
 * no employee link (null, and nulls never collide in the unique index),
 * and double submission is a database constraint (`campaign_id` +
 * `respondent_key`), not a check-then-insert the clock could race. Owned
 * rows cascade with their campaign, response, or template; author links
 * null so the survey outlives the login.
 *
 * Numbered `000032`: the plan's `000029` is spent (performance settings),
 * like every pre-assigned number since `000024`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createSurveyTemplates();
        $this->createSurveyQuestions();
        $this->createSurveyCampaigns();
        $this->createSurveyResponses();
        $this->createSurveyAnswers();
        $this->createSurveyResults();
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_results');
        Schema::dropIfExists('survey_answers');
        Schema::dropIfExists('survey_responses');
        Schema::dropIfExists('survey_campaigns');
        Schema::dropIfExists('survey_questions');
        Schema::dropIfExists('survey_templates');
    }

    private function createSurveyTemplates(): void
    {
        if (Schema::hasTable('survey_templates')) {
            return;
        }

        Schema::create('survey_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->enum('type', ['pulse', 'engagement', 'onboarding_exit', 'exit', 'custom'])->default('pulse');
            $table->boolean('is_anonymous')->default(false);
            $table->boolean('is_active')->default(true);
            $table->enum('frequency', ['one_time', 'weekly', 'monthly', 'quarterly', 'annual'])->default('one_time');
            $table->enum('audience_scope', ['all', 'department', 'role', 'location', 'explicit'])->default('all');
            $table->json('audience_meta')->nullable();
            $table->json('settings')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    private function createSurveyQuestions(): void
    {
        if (Schema::hasTable('survey_questions')) {
            return;
        }

        Schema::create('survey_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('survey_templates')->cascadeOnDelete();
            $table->text('text');
            $table->enum('type', ['scale', 'text', 'multiple_choice', 'yes_no', 'nps'])->default('scale');
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->decimal('min', 12, 2)->nullable();
            $table->decimal('max', 12, 2)->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();
        });
    }

    private function createSurveyCampaigns(): void
    {
        if (Schema::hasTable('survey_campaigns')) {
            return;
        }

        Schema::create('survey_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('survey_templates')->cascadeOnDelete();
            $table->string('name');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->enum('status', ['scheduled', 'open', 'closed'])->default('scheduled');
            $table->unsignedInteger('anonymity_threshold')->default(5);
            $table->boolean('notify_on_publish')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    private function createSurveyResponses(): void
    {
        if (Schema::hasTable('survey_responses')) {
            return;
        }

        Schema::create('survey_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('survey_campaigns')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->char('respondent_key', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'employee_id']);
            $table->unique(['campaign_id', 'respondent_key']);
        });
    }

    private function createSurveyAnswers(): void
    {
        if (Schema::hasTable('survey_answers')) {
            return;
        }

        Schema::create('survey_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('response_id')->constrained('survey_responses')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('survey_questions')->cascadeOnDelete();
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 12, 2)->nullable();
            $table->json('value_json')->nullable();
            $table->timestamps();
        });
    }

    private function createSurveyResults(): void
    {
        if (Schema::hasTable('survey_results')) {
            return;
        }

        Schema::create('survey_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('survey_campaigns')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('survey_questions')->cascadeOnDelete();
            $table->json('aggregates')->nullable();
            $table->unsignedInteger('response_count')->default(0);
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'question_id']);
        });
    }
};
