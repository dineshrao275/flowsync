<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15 — HRMS onboarding & offboarding lifecycle (tenant database).
 *
 * Eight tables, one workflow: a template is the reusable checklist, a case is
 * the checklist running for one person, and `document_requests` is the hinge
 * between the checklist and the document store — the reason Phase 13 had to
 * ship first (P4.1’s `document_requests.document_type_id` is a real FK to
 * `document_types`).
 *
 * Three deliberate deviations from the P4.1 sketch, each documented where it
 * bites:
 *
 *   1. **`document_requests` carries `document_id`.** The sketch omits it, but
 *      the phase’s own prerequisite note talks about “a null `document_id`” —
 *      and without the column there is no link between a request and its
 *      fulfillment at all. The hinge story collapses to “HR asked, something
 *      happened somewhere”. Nullable FK to `employee_documents`, nullOnDelete:
 *      deleting the file orphans the request rather than destroying the ask.
 *      `timestamps` and a nullable `due_date` likewise: an open-ended request
 *      is ordinary, and an audit trail needs a created_at.
 *   2. **Case-level `category`/`owner_scope` are plain strings, not enums.**
 *      The template side is the catalogue and keeps its CHECKs; the case row
 *      is a *snapshot* of the template row at materialisation. An enum on the
 *      snapshot means a new template category needs a migration on old case
 *      rows before it can be used — the catalogue would be held hostage by its
 *      own history. The service is the only writer and copies validated
 *      values, so the snapshot needs no second guard.
 *   3. **`asset_id`/`expense_claim_id` are bare nullable integers, no FK.**
 *      Assets land in P14 and expense claims in P8; a constraint against a
 *      missing table is an error at creation time on both grammars. The
 *      constraints land with their phases.
 *
 * **`onboarding_cases.employee_id` is unique.** One onboarding per employee,
 * ever — a second hire is a rehire, and a rehire reopens (or replaces, by
 * hard-deleting) the old case rather than silently starting a parallel one.
 * Offboarding has no such constraint: people can leave twice.
 *
 * **No `tenant_id` column** — the tenant database is the isolation boundary
 * (Phase 13).
 *
 * Repair-safe and every step independent: `tenants:provision` re-runs tenant
 * migrations to repair a database that failed partway. Each table is created
 * only when absent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createOnboardingTemplates();
        $this->createOnboardingTemplateTasks();
        $this->createOnboardingCases();
        $this->createOnboardingCaseTasks();
        $this->createOffboardingCases();
        $this->createOffboardingCaseTasks();
        $this->createExitClearances();
        $this->createDocumentRequests();
    }

    public function down(): void
    {
        Schema::dropIfExists('document_requests');
        Schema::dropIfExists('exit_clearances');
        Schema::dropIfExists('offboarding_case_tasks');
        Schema::dropIfExists('offboarding_cases');
        Schema::dropIfExists('onboarding_case_tasks');
        Schema::dropIfExists('onboarding_cases');
        Schema::dropIfExists('onboarding_template_tasks');
        Schema::dropIfExists('onboarding_templates');
    }

    private function createOnboardingTemplates(): void
    {
        if (Schema::hasTable('onboarding_templates')) {
            return;
        }

        Schema::create('onboarding_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    private function createOnboardingTemplateTasks(): void
    {
        if (Schema::hasTable('onboarding_template_tasks')) {
            return;
        }

        Schema::create('onboarding_template_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('onboarding_templates')->cascadeOnDelete();
            $table->string('title');
            $table->string('description')->nullable();

            // The catalogue side keeps its CHECKs: these are the only values
            // a template task may carry, and the picker is built from them.
            $table->enum('category', ['document', 'task', 'asset', 'access', 'orientation', 'other']);
            $table->enum('owner_scope', ['hr', 'manager', 'employee', 'it']);

            // Days after the joining date the item falls due. Zero means “day
            // one”, negative means “before they start” (a laptop imaged the
            // Friday before a Monday start is a negative offset, not a hack).
            $table->integer('due_offset_days')->default(0);
            $table->boolean('is_mandatory')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['template_id', 'position']);
            $table->index('category');
        });
    }

    private function createOnboardingCases(): void
    {
        if (Schema::hasTable('onboarding_cases')) {
            return;
        }

        Schema::create('onboarding_cases', function (Blueprint $table) {
            $table->id();
            // Unique: one onboarding per employee, ever. See the class docblock
            // for why a rehire reopens rather than duplicates.
            $table->foreignId('employee_id')->unique()->constrained('employees')->cascadeOnDelete();
            // The template as it was when the case started: nullOnDelete, so
            // deleting a catalogue row does not abort the cases already running
            // from it — they carry their own materialised copies.
            $table->foreignId('template_id')->nullable()->constrained('onboarding_templates')->nullOnDelete();
            $table->enum('status', ['not_started', 'in_progress', 'completed', 'cancelled'])->default('not_started');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('template_id');
            $table->index('status');
        });
    }

    private function createOnboardingCaseTasks(): void
    {
        if (Schema::hasTable('onboarding_case_tasks')) {
            return;
        }

        Schema::create('onboarding_case_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('onboarding_cases')->cascadeOnDelete();
            // The template row this was materialised from, if any: ad-hoc
            // tasks added mid-case have no template behind them, and a deleted
            // template row must not take the running case with it.
            $table->foreignId('template_task_id')->nullable()->constrained('onboarding_template_tasks')->nullOnDelete();
            $table->string('title');
            $table->string('description')->nullable();
            // Plain strings, not enums: a snapshot of the template values at
            // materialisation (see the class docblock).
            $table->string('category', 32);
            $table->string('owner_scope', 32);
            $table->foreignId('owner_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->enum('status', ['pending', 'in_progress', 'done', 'skipped', 'waived'])->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            // The materialised order: template position at creation, then
            // whatever the case’s own reordering says. Without it the
            // checklist renders in id order, which is creation order only by
            // accident the moment anyone inserts a task mid-case.
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['case_id', 'status']);
            $table->index(['case_id', 'position']);
            $table->index('owner_employee_id');
            $table->index(['owner_employee_id', 'due_date']);
        });
    }

    private function createOffboardingCases(): void
    {
        if (Schema::hasTable('offboarding_cases')) {
            return;
        }

        Schema::create('offboarding_cases', function (Blueprint $table) {
            $table->id();
            // Deliberately NOT unique: people can leave twice, and each exit
            // is its own case with its own clearance.
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('last_working_day');
            $table->enum('reason', ['resigned', 'terminated', 'retired', 'contract_end', 'other']);
            $table->enum('status', ['initiated', 'in_progress', 'completed', 'cancelled'])->default('initiated');
            $table->unsignedInteger('notice_period_days')->nullable();
            $table->timestamp('exit_interview_at')->nullable();
            $table->text('exit_interview_notes')->nullable();
            $table->timestamp('resignation_received_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status']);
        });
    }

    private function createOffboardingCaseTasks(): void
    {
        if (Schema::hasTable('offboarding_case_tasks')) {
            return;
        }

        Schema::create('offboarding_case_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('offboarding_cases')->cascadeOnDelete();
            $table->string('title');
            $table->string('description')->nullable();
            // The onboarding shape, plus linkage — see the class docblock for
            // why these two are bare integers with no FK yet.
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->unsignedBigInteger('expense_claim_id')->nullable();
            $table->string('category', 32);
            $table->string('owner_scope', 32);
            $table->foreignId('owner_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->enum('status', ['pending', 'in_progress', 'done', 'skipped', 'waived'])->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['case_id', 'status']);
            $table->index('owner_employee_id');
            $table->index(['owner_employee_id', 'due_date']);
            $table->index('asset_id');
        });
    }

    private function createExitClearances(): void
    {
        if (Schema::hasTable('exit_clearances')) {
            return;
        }

        Schema::create('exit_clearances', function (Blueprint $table) {
            $table->id();
            // One clearance per case: the sign-off is the case’s, not a
            // running tally anyone can append to twice.
            $table->foreignId('case_id')->unique()->constrained('offboarding_cases')->cascadeOnDelete();
            $table->unsignedInteger('pending_assets_count')->default(0);
            // Days, not money: leave encashment is settled in payroll, and a
            // day-count here is what the reviewer checks against the balance.
            $table->unsignedInteger('pending_leave_encashment_days')->default(0);
            // Minor units (paise/cents), never a float: floats round, and a
            // clearance that rounds is a clearance that lies by a paisa.
            $table->unsignedBigInteger('pending_expense_amount')->default(0);
            $table->unsignedInteger('pending_documents_count')->default(0);
            $table->boolean('dues_settled')->default(false);
            $table->foreignId('cleared_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cleared_at')->nullable();
            $table->json('blocked_reasons')->nullable();
            $table->timestamps();

            $table->index('dues_settled');
        });
    }

    private function createDocumentRequests(): void
    {
        if (Schema::hasTable('document_requests')) {
            return;
        }

        Schema::create('document_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            // The FK Phase 13 exists for: a request names a catalogue type,
            // and retiring the type orphans the request rather than deleting
            // the ask.
            $table->foreignId('document_type_id')->nullable()->constrained('document_types')->nullOnDelete();
            // The fulfillment: the submitted file, when there is one. A
            // deleted file nulls the link rather than destroying the request —
            // “HR asked, the file is gone” is a state the case can see, while
            // a cascaded row would silently un-ask the question.
            $table->foreignId('document_id')->nullable()->constrained('employee_documents')->nullOnDelete();
            $table->string('title');
            $table->date('due_date')->nullable();
            $table->enum('status', ['pending', 'submitted', 'accepted', 'waived', 'rejected'])->default('pending');
            $table->enum('source', ['onboarding', 'offboarding', 'hr'])->default('hr');
            // The case this was raised from, when it was raised from one: a
            // plain pair, not a morph, because the two case tables are fixed
            // and a morph string is a typo away from pointing nowhere.
            $table->string('case_type', 32)->nullable();
            $table->unsignedBigInteger('case_id')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index('employee_id');
            $table->index(['employee_id', 'due_date']);
            $table->index('document_type_id');
            $table->index('document_id');
            $table->index('status');
            $table->index(['case_type', 'case_id']);
        });
    }
};
