<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15 — HRMS comp-off tables (tenant database).
 *
 *   comp_off_credits              — time banked for working when others rest.
 *       One row per (employee, date, source): the unique triple is what
 *       makes accrual reruns idempotent rather than double-crediting.
 *       `created_at` only — a credit is never edited, it expires (the row
 *       stays, `expiry_date` passes) or is spent through a request.
 *   comp_off_requests             — redeeming banked time, with the same
 *       lifecycle vocabulary as leave asks (submitted first, never draft:
 *       filing opens the chain immediately). Soft-deleted so a withdrawn
 *       ask stays queryable for audit.
 *   comp_off_request_days         — the per-day split, derived from the
 *       request like leave's split rows: re-split, never edited, so no
 *       timestamps.
 *
 * Balance is derived (`SUM(credits) - SUM(approved minutes)` over
 * unexpired credits) — the volumes are small and a materialised column
 * would be a staleness bug factory. See `CompOffService` (P7.2).
 *
 * FK choices mirror leave: employee-owned rows cascade; `approval_id` /
 * `document_id` / user references null on delete (a deleted approver,
 * file or account orphans the link, never destroys the ask).
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
        $this->createCompOffCredits();
        $this->createCompOffRequests();
        $this->createCompOffRequestDays();
    }

    public function down(): void
    {
        Schema::dropIfExists('comp_off_request_days');
        Schema::dropIfExists('comp_off_requests');
        Schema::dropIfExists('comp_off_credits');
    }

    private function createCompOffCredits(): void
    {
        if (Schema::hasTable('comp_off_credits')) {
            return;
        }

        Schema::create('comp_off_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('work_date');

            // weekend = rostered off, holiday = calendar holiday (P8 fills
            // that branch), special = declared by policy, manual = HR grant.
            $table->enum('source_type', ['weekend', 'holiday', 'special', 'manual']);

            // Unsigned: a credit is time banked, never a negative. SQLite
            // ignores the unsigned modifier; PostgreSQL enforces >= 0.
            $table->unsignedInteger('minutes');
            $table->date('expiry_date')->nullable();
            $table->text('note')->nullable();

            // A credit row must outlive the users around it: the granter, the
            // actor, and the ledger row all survive each other.
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // created_at only: append-only, like the other ledgers.
            $table->timestamp('created_at')->nullable();

            $table->unique(['employee_id', 'work_date', 'source_type']);
        });
    }

    private function createCompOffRequests(): void
    {
        if (Schema::hasTable('comp_off_requests')) {
            return;
        }

        Schema::create('comp_off_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->unsignedInteger('total_minutes')->default(0);
            $table->text('reason');

            $table->enum('status', ['submitted', 'pending', 'approved', 'rejected', 'cancelled'])
                ->default('submitted');

            // The shared approvals engine decides these; deleting the
            // approval nulls the link rather than destroying the ask.
            $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();

            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // A deleted file orphans the link ("asked, file gone") instead of
            // un-asking the request.
            $table->foreignId('document_id')->nullable()->constrained('employee_documents')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status']);
            $table->index('status');
        });
    }

    private function createCompOffRequestDays(): void
    {
        if (Schema::hasTable('comp_off_request_days')) {
            return;
        }

        Schema::create('comp_off_request_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comp_off_request_id')->constrained('comp_off_requests')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('minutes');

            $table->unique(['comp_off_request_id', 'date']);
        });
    }
};
