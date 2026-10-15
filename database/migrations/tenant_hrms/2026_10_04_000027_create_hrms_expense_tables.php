<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRMS/P11.1 — the expense claim tables.
 *
 * Three repair-safe tables (`hasTable` guards — `tenants:provision` re-runs
 * migrations that failed partway):
 *
 *   expense_categories — the tenant's claim catalogue (seeded starters are
 *     `is_system`: renamable, never deletable while claimed against).
 *   expense_claims     — one claim with a server-stamped number, a small
 *     state machine, and the payroll hand-off columns. Totals are
 *     server-computed from the items, never trusted from the client.
 *   expense_claim_items — the receipted lines. Category and receipt links
 *     null on delete: pruning the catalogue or a file must never take the
 *     claim it evidenced with it.
 *
 * Numbered `000027`: the plan's `000024` is taken (compensation settings),
 * and so is `000025` — pre-assigned numbers are advisory once follow-ups
 * spend them. References only tables from earlier migrations (employees,
 * salary_components, employee_documents, approvals, payroll_runs, users).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createExpenseCategories();
        $this->createExpenseClaims();
        $this->createExpenseClaimItems();
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_claim_items');
        Schema::dropIfExists('expense_claims');
        Schema::dropIfExists('expense_categories');
    }

    private function createExpenseCategories(): void
    {
        if (Schema::hasTable('expense_categories')) {
            return;
        }

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('requires_receipt_above', 14, 2)->nullable();
            $table->boolean('is_reimbursable')->default(true);

            // The pay head a reimbursement posts to, when the tenant maps
            // one: a deleted head orphans the link while the category keeps
            // working as an ad-hoc earning line.
            $table->foreignId('payroll_component_id')->nullable()->constrained('salary_components')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });
    }

    private function createExpenseClaims(): void
    {
        if (Schema::hasTable('expense_claims')) {
            return;
        }

        Schema::create('expense_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('claim_number')->unique();
            $table->date('claim_date');
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->string('purpose');
            $table->text('description')->nullable();
            $table->char('currency', 3)->default('USD');
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('approved_amount', 14, 2)->nullable();
            $table->decimal('reimbursed_amount', 14, 2)->nullable();

            $table->enum('status', ['draft', 'submitted', 'pending', 'approved', 'rejected', 'paid', 'cancelled'])
                ->default('draft');

            $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();
            $table->foreignId('paid_in_payroll_run_id')->nullable()->constrained('payroll_runs')->nullOnDelete();
            $table->enum('paid_via', ['payroll', 'manual'])->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status']);
            $table->index(['period_year', 'period_month']);
        });
    }

    private function createExpenseClaimItems(): void
    {
        if (Schema::hasTable('expense_claim_items')) {
            return;
        }

        Schema::create('expense_claim_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_id')->constrained('expense_claims')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $table->string('description');
            $table->decimal('amount', 14, 2);
            $table->date('spent_at')->nullable();
            $table->string('vendor')->nullable();
            $table->foreignId('receipt_document_id')->nullable()->constrained('employee_documents')->nullOnDelete();
            $table->boolean('is_billable')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }
};
