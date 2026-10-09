<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15 — HRMS payroll tables, core (tenant database).
 *
 *   salary_components             — the tenant's pay heads (basic, HRA,
 *       PF-employee …). `is_system` marks seeded rows a tenant may rename
 *       but not delete; `calculation_type` tells the engine how the value
 *       resolves (P9.2); percentages and money stay in decimals, never
 *       floats (0.6).
 *   salary_structures             — named CTC templates, versioned by
 *       `effective_from`; `salary_structure_components` links heads with
 *       per-structure values (composite PK, a pure pivot, so no
 *       timestamps — the Laravel pivot convention, not an oversight).
 *   employee_salary_structures    — who earns what, when. One row per
 *       (employee, effective_from); `is_current` marks the live one.
 *   salary_revisions              — CTC changes walking an approval chain
 *       to an applied structure, with the offer letter linked.
 *   payslip_templates             — render templates for payslips. Config
 *       rows, deliberately timestamp-less like the other catalogue
 *       tables — a template has no lifecycle to audit.
 *   payroll_runs                  — one row per pay period (unique), walking
 *       `draft → calculating → review → approved → processing → paid`,
 *       with `void`/`locked` as terminal exits. Totals snapshot the run.
 *   payslips                      — one row per employee per run (unique),
 *       snapshotting inputs (days, JSON pay heads) and outputs (money).
 *       The snapshot is the point: recomputing history from live tables
 *       would let a later rule change rewrite a locked payslip.
 *   payslip_adjustments           — one-off lines on a payslip (bonus,
 *       recovery), referencing their source where one exists. Derived
 *       rows, so no timestamps — they are re-issued, never edited.
 *
 * FK choices: employee/run-owned rows cascade (history without its owner
 * is noise); catalogue links (`leave_type`-style) are NO ACTION — the
 * service refuses to delete a structure or component in use, and the
 * database backstops the refusal instead of cascading history away;
 * user/file/approval links null (a deleted account must orphan the link,
 * never destroy the payroll record).
 *
 * **No `tenant_id` column** — the tenant database *is* the boundary (Phase 13).
 *
 * Repair-safe: every table is created only when absent, because
 * `tenants:provision` re-runs tenant migrations to repair a database that
 * failed partway.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createSalaryComponents();
        $this->createSalaryStructures();
        $this->createSalaryStructureComponents();
        $this->createEmployeeSalaryStructures();
        $this->createSalaryRevisions();
        $this->createPayslipTemplates();
        $this->createPayrollRuns();
        $this->createPayslips();
        $this->createPayslipAdjustments();
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_adjustments');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('payslip_templates');
        Schema::dropIfExists('salary_revisions');
        Schema::dropIfExists('employee_salary_structures');
        Schema::dropIfExists('salary_structure_components');
        Schema::dropIfExists('salary_structures');
        Schema::dropIfExists('salary_components');
    }

    private function createSalaryComponents(): void
    {
        if (Schema::hasTable('salary_components')) {
            return;
        }

        Schema::create('salary_components', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('code', 32)->nullable();

            $table->enum('type', ['earning', 'deduction', 'employer_contribution', 'reimbursement']);
            $table->enum('calculation_type', ['fixed', 'percentage_of_ctc', 'percentage_of_basic', 'formula'])
                ->default('fixed');
            $table->decimal('default_value', 14, 2)->default(0);

            $table->boolean('is_taxable')->default(true);
            $table->boolean('is_prorated')->default(true);

            // Managed by the statutory engine (P10), never hand-edited once
            // it computes — the flag is the boundary between the two.
            $table->boolean('is_statutory')->default(false);

            // Seeded rows a tenant may rename but not delete or repurpose.
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sequence')->default(0);

            $table->timestamps();

            $table->index('type');
            $table->index('is_active');
        });
    }

    private function createSalaryStructures(): void
    {
        if (Schema::hasTable('salary_structures')) {
            return;
        }

        Schema::create('salary_structures', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('currency', 3)->default('USD');
            $table->date('effective_from');
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            // A structure outlives its author: deleting the login nulls the
            // link instead of taking the template with it.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    private function createSalaryStructureComponents(): void
    {
        if (Schema::hasTable('salary_structure_components')) {
            return;
        }

        Schema::create('salary_structure_components', function (Blueprint $table) {
            $table->foreignId('structure_id')->constrained('salary_structures')->cascadeOnDelete();
            $table->foreignId('component_id')->constrained('salary_components')->cascadeOnDelete();

            // Four decimals: percentages of CTC resolve to fractions of a
            // paisa that rounding to two would silently drop across heads.
            $table->decimal('value', 14, 4)->default(0);
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->boolean('is_override')->default(false);

            $table->primary(['structure_id', 'component_id']);
        });
    }

    private function createEmployeeSalaryStructures(): void
    {
        if (Schema::hasTable('employee_salary_structures')) {
            return;
        }

        Schema::create('employee_salary_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            // NO ACTION on purpose (no cascade, no null): the service
            // refuses to delete a structure with assignments behind it, and
            // the database backstops the refusal instead of cascading
            // history away.
            $table->foreignId('structure_id')->constrained('salary_structures');

            $table->decimal('ctc_annual', 14, 2);
            $table->decimal('monthly_ctc', 14, 2);
            $table->decimal('gross_monthly', 14, 2);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->text('reason')->nullable();
            $table->boolean('is_current')->default(false);
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['employee_id', 'effective_from']);
            $table->index('is_current');
        });
    }

    private function createSalaryRevisions(): void
    {
        if (Schema::hasTable('salary_revisions')) {
            return;
        }

        Schema::create('salary_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->decimal('from_ctc', 14, 2);
            $table->decimal('to_ctc', 14, 2);
            $table->decimal('change_percent', 6, 2)->default(0);
            $table->date('effective_from');
            $table->text('reason')->nullable();

            $table->enum('status', ['draft', 'approved', 'rejected', 'applied'])
                ->default('draft');

            // The shared approvals engine decides these; deleting the
            // approval nulls the link rather than destroying the revision.
            $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();

            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            // The offer letter, materialised as a document (P13): deleting
            // the file orphans the link, never the revision.
            $table->foreignId('letter_document_id')->nullable()->constrained('employee_documents')->nullOnDelete();

            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });
    }

    private function createPayslipTemplates(): void
    {
        if (Schema::hasTable('payslip_templates')) {
            return;
        }

        Schema::create('payslip_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->json('content')->nullable();
            $table->boolean('is_active')->default(true);
        });
    }

    private function createPayrollRuns(): void
    {
        if (Schema::hasTable('payroll_runs')) {
            return;
        }

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->date('pay_period_start');
            $table->date('pay_period_end');
            $table->date('pay_date');

            $table->enum('status', ['draft', 'calculating', 'review', 'approved', 'processing', 'paid', 'void', 'locked'])
                ->default('draft');

            $table->unsignedInteger('employee_count')->default(0);
            $table->json('totals')->nullable();

            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['period_year', 'period_month']);
        });
    }

    private function createPayslips(): void
    {
        if (Schema::hasTable('payslips')) {
            return;
        }

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            // NO ACTION, like structures above: a locked payslip pins its
            // structure row, and the database refuses the delete the
            // service already refused.
            $table->foreignId('employee_salary_structure_id')->constrained('employee_salary_structures');

            $table->json('earnings')->nullable();
            $table->json('deductions')->nullable();
            $table->json('employer_contributions')->nullable();
            $table->json('statutory')->nullable();

            $table->decimal('gross_pay', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);
            $table->decimal('net_pay', 14, 2)->default(0);

            $table->decimal('working_days', 5, 2)->default(0);
            $table->decimal('paid_days', 5, 2)->default(0);
            $table->decimal('lop_days', 5, 2)->default(0);
            $table->unsignedInteger('ot_minutes')->default(0);
            $table->unsignedInteger('absent_days')->default(0);
            $table->json('leave_days')->nullable();

            $table->enum('status', ['draft', 'published', 'disputed', 'paid'])
                ->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('locked_at')->nullable();

            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
        });
    }

    private function createPayslipAdjustments(): void
    {
        if (Schema::hasTable('payslip_adjustments')) {
            return;
        }

        Schema::create('payslip_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payslip_id')->constrained('payslips')->cascadeOnDelete();

            // Nullable: an ad-hoc line may name no catalogue component, and
            // a deleted component orphans the link while the label keeps
            // the row readable.
            $table->foreignId('component_id')->nullable()->constrained('salary_components')->nullOnDelete();

            $table->enum('kind', ['earning', 'deduction']);
            $table->string('label');
            $table->decimal('amount', 14, 2);

            // The row that caused the line (an encashed leave ledger row, a
            // claim): polymorphic by hand because the ledger predates any
            // one source.
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->text('note')->nullable();

            // A ledger row must outlive the user who made it.
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();

            // created_at only: append-only, like the other ledgers.
            $table->timestamp('created_at')->nullable();

            $table->index('actor_user_id');
        });
    }
};
