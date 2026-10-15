<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15 — HRMS leave tables (tenant database).
 *
 *   leave_types                 — the tenant's leave catalog (casual, sick,
 *       earned…). `is_system` marks seeded rows a tenant may rename but not
 *       delete; `accrual_method = none` means "granted, never auto-accrued".
 *   leave_policies              — accrual/calendar rules; `leave_policy_types`
 *       links policies to types (composite PK, a pure pivot, so no timestamps
 *       — the Laravel pivot convention, not an oversight).
 *   leave_balances              — materialised projection, one row per
 *       (employee, type, year). Rebuilt from the ledger by
 *       `rebuildBalance()`, which is why "why is my balance 4.5?" is
 *       answerable and accrual reruns are safe.
 *   leave_adjustments           — the append-only ledger, the source of truth.
 *       `quantity` is signed (accruals credit, avails debit); `reference_*`
 *       points at the row that caused the entry (a request, an encashment).
 *       `created_at` only, like the other ledgers.
 *   leave_requests              — one ask with its day split (`from_half` /
 *       `to_half` for half days) and its lifecycle (`draft → submitted →
 *       pending → approved|rejected`, plus `cancelled`). Soft-deleted so a
 *       withdrawn ask stays queryable for audit.
 *   leave_request_days          — the per-day split, derived from the request
 *       (week-offs/holidays excluded from `total_days`). Derived rows, so no
 *       timestamps — they are re-split, never edited.
 *   leave_exemption_requests    — statutory leave-exemption asks (P6.4 wires
 *       the jurisdiction gate; the table is deliberately unopinionated).
 *
 * FK choices: employee-owned rows cascade (an employee's leave history has
 * no meaning without the record); `approval_id` / `document_id` / user
 * references null on delete (a deleted approver, file or account must orphan
 * the link, never destroy the ask); `leave_type_id` on asks is NO ACTION —
 * the service refuses to delete a type in use, and the database backstops
 * it rather than cascading history away.
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
        $this->createLeaveTypes();
        $this->createLeavePolicies();
        $this->createLeavePolicyTypes();
        $this->createLeaveBalances();
        $this->createLeaveAdjustments();
        $this->createLeaveRequests();
        $this->createLeaveRequestDays();
        $this->createLeaveExemptionRequests();
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_exemption_requests');
        Schema::dropIfExists('leave_request_days');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_adjustments');
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('leave_policy_types');
        Schema::dropIfExists('leave_policies');
        Schema::dropIfExists('leave_types');
    }

    private function createLeaveTypes(): void
    {
        if (Schema::hasTable('leave_types')) {
            return;
        }

        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();

            // Optional short label for payroll exports ("CL", "SL"); custom
            // tenant types may carry no code at all.
            $table->string('code', 32)->nullable();

            $table->boolean('is_paid')->default(true);

            // `none` = granted, never auto-accrued. The default forces an
            // explicit choice per type rather than silently accruing.
            $table->enum('accrual_method', ['none', 'annual', 'monthly', 'quarterly', 'per_payroll'])
                ->default('none');
            $table->decimal('accrual_rate', 6, 3)->default(0);
            $table->decimal('max_balance', 6, 2)->nullable();

            $table->boolean('carry_forward')->default(false);
            $table->decimal('carry_forward_cap', 6, 2)->nullable();
            $table->boolean('encashable')->default(false);

            $table->unsignedInteger('requires_document_after_days')->nullable();
            $table->decimal('min_days_per_request', 5, 2)->nullable();
            $table->decimal('max_days_per_year', 5, 2)->nullable();

            // Permissive by default: half-day asks are ordinary, and the flag
            // exists for tenants to restrict, not for the schema to refuse.
            $table->boolean('allow_half_day')->default(true);
            $table->boolean('allow_negative_balance')->default(false);

            // Hex, like every other color in the app (P2.6).
            $table->string('color', 16)->nullable();
            $table->unsignedSmallInteger('position')->default(0);

            // Seeded rows a tenant may rename or reorder but not delete.
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index('position');
            $table->index('is_active');
        });
    }

    private function createLeavePolicies(): void
    {
        if (Schema::hasTable('leave_policies')) {
            return;
        }

        Schema::create('leave_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();

            $table->enum('accrual_period', ['monthly', 'quarterly', 'biannual', 'annual'])
                ->default('annual');

            // 1–12. The leave year need not start in January.
            $table->unsignedTinyInteger('start_month')->default(1);

            // Day of the month the carry-forward runs; null = on the period
            // rollover itself rather than a fixed calendar day.
            $table->unsignedTinyInteger('carry_forward_day')->nullable();

            $table->decimal('max_carry_forward', 6, 2)->nullable();
            $table->boolean('negative_balance_allowed')->default(false);
            $table->decimal('max_negative_days', 5, 2)->nullable();

            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    private function createLeavePolicyTypes(): void
    {
        if (Schema::hasTable('leave_policy_types')) {
            return;
        }

        Schema::create('leave_policy_types', function (Blueprint $table) {
            $table->foreignId('policy_id')->constrained('leave_policies')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();

            $table->primary(['policy_id', 'leave_type_id']);
        });
    }

    private function createLeaveBalances(): void
    {
        if (Schema::hasTable('leave_balances')) {
            return;
        }

        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');

            // All signed decimal(6,2): a balance is arithmetic, never money,
            // and never a float (0.6-day asks must add up exactly).
            $table->decimal('opening', 6, 2)->default(0);
            $table->decimal('accrued', 6, 2)->default(0);
            $table->decimal('availed', 6, 2)->default(0);
            $table->decimal('encashed', 6, 2)->default(0);
            $table->decimal('lapsed', 6, 2)->default(0);
            $table->decimal('carried_forward', 6, 2)->default(0);
            $table->decimal('adjusted', 6, 2)->default(0);
            $table->decimal('balance', 6, 2)->default(0);

            $table->timestamps();

            $table->unique(['employee_id', 'leave_type_id', 'year']);
        });
    }

    private function createLeaveAdjustments(): void
    {
        if (Schema::hasTable('leave_adjustments')) {
            return;
        }

        Schema::create('leave_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');

            $table->enum('kind', ['opening', 'accrual', 'carry_forward', 'encashment', 'lapse', 'adjustment', 'availed']);

            // Signed: accruals credit, avails debit. The balance is the sum,
            // so the sign is the meaning — an unsigned column would force a
            // second "direction" field saying the same thing twice.
            $table->decimal('quantity', 6, 2);

            // The row that caused the entry (a leave request, an encashment):
            // polymorphic by hand because the ledger predates any one source.
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->text('note')->nullable();

            // A ledger row must outlive the user who made it.
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();

            // created_at only: append-only, like the other ledgers.
            $table->timestamp('created_at')->nullable();

            // "Show me this employee's ledger for this type and year", which
            // is the rebuild read path.
            $table->index(['employee_id', 'leave_type_id', 'year']);
            $table->index('actor_user_id');
        });
    }

    private function createLeaveRequests(): void
    {
        if (Schema::hasTable('leave_requests')) {
            return;
        }

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            // NO ACTION on purpose (no cascade, no null): the service refuses
            // to delete a type with requests behind it, and the database
            // backstops the refusal instead of cascading history away.
            $table->foreignId('leave_type_id')->constrained('leave_types');

            $table->date('from_date');
            $table->date('to_date');
            $table->enum('from_half', ['full', 'first_half', 'second_half'])->default('full');
            $table->enum('to_half', ['full', 'first_half', 'second_half'])->default('full');
            $table->decimal('total_days', 5, 2)->default(0);

            $table->text('reason');
            $table->string('contact_during_leave')->nullable();

            // A deleted file orphans the link ("asked, file gone") instead of
            // un-asking the request.
            $table->foreignId('document_id')->nullable()->constrained('employee_documents')->nullOnDelete();

            $table->enum('status', ['draft', 'submitted', 'pending', 'approved', 'rejected', 'cancelled'])
                ->default('draft');

            // The shared approvals engine decides these; deleting the
            // approval nulls the link rather than destroying the ask.
            $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();

            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status']);
            $table->index('status');
        });
    }

    private function createLeaveRequestDays(): void
    {
        if (Schema::hasTable('leave_request_days')) {
            return;
        }

        Schema::create('leave_request_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained('leave_requests')->cascadeOnDelete();
            $table->date('date');
            $table->boolean('is_holiday')->default(false);
            $table->boolean('is_week_off')->default(false);
            $table->boolean('is_half_day')->default(false);

            $table->unique(['leave_request_id', 'date']);
        });
    }

    private function createLeaveExemptionRequests(): void
    {
        if (Schema::hasTable('leave_exemption_requests')) {
            return;
        }

        Schema::create('leave_exemption_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            // NO ACTION, like leave_requests: history outlives the catalog.
            $table->foreignId('leave_type_id')->constrained('leave_types');

            $table->date('from_date');
            $table->date('to_date');
            $table->decimal('days', 5, 2);
            $table->text('reason');

            $table->enum('status', ['pending', 'approved', 'rejected', 'expired'])
                ->default('pending');
            $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();

            $table->unsignedSmallInteger('fiscal_year');
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });
    }
};
