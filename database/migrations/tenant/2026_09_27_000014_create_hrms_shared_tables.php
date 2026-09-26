<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15 — HRMS shared tables (tenant database).
 *
 * The four primitives every later phase builds on:
 *
 *   approvals / approval_steps  — one generic multi-step engine. `approvable_*`
 *       is polymorphic so leave requests, expense claims, salary revisions,
 *       offboarding clearance and documents all reuse it instead of each
 *       growing its own approval columns.
 *   hrms_audit_logs             — append-only business record of who changed
 *       which record. Deliberately has **no updated_at**: a row is never
 *       rewritten, so the table is a true ledger. Separate from
 *       `hrms_data_access_logs` and from the `hrms` log channel.
 *   hrms_data_access_logs        — append-only record of *reads* of sensitive
 *       data (view/download/export), which the audit table cannot express.
 *   hrms_settings                — the single operational settings row.
 *
 * Repair-safe: every table is created only when absent and the settings row is
 * an `updateOrInsert`, because `tenants:provision` re-runs tenant migrations to
 * repair a database that failed partway. No `tenant_id` column — the tenant
 * database *is* the boundary (Phase 13).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createApprovals();
        $this->createApprovalSteps();
        $this->createAuditLogs();
        $this->createDataAccessLogs();
        $this->createSettings();
        $this->backfillSettings();
    }

    public function down(): void
    {
        // Append-only ledgers (hrms_audit_logs, hrms_data_access_logs) are
        // dropped with the rest; approvals cascade to their steps.
        Schema::dropIfExists('hrms_settings');
        Schema::dropIfExists('hrms_data_access_logs');
        Schema::dropIfExists('hrms_audit_logs');
        Schema::dropIfExists('approval_steps');
        Schema::dropIfExists('approvals');
    }

    private function createApprovals(): void
    {
        if (Schema::hasTable('approvals')) {
            return;
        }

        Schema::create('approvals', function (Blueprint $table) {
            $table->id();

            // What is being approved. Polymorphic on purpose: one engine, many
            // subjects (D2.4).
            $table->string('approvable_type');
            $table->unsignedBigInteger('approvable_id');

            // Human-readable denormalized subject, e.g. "Annual leave — Jane Doe".
            $table->string('subject');

            $table->string('action')->default('approve');

            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])
                ->default('pending');

            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('requested_by_employee_id')->nullable();

            $table->unsignedInteger('current_step')->default(1);
            $table->timestamp('due_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_note')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['approvable_type', 'approvable_id']);
            $table->index(['status', 'current_step']);
            $table->index(['requested_by_user_id', 'created_at']);
            $table->index('due_at');
        });
    }

    private function createApprovalSteps(): void
    {
        if (Schema::hasTable('approval_steps')) {
            return;
        }

        Schema::create('approval_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_id')->constrained('approvals')->cascadeOnDelete();

            // 1-based, dense per approval — the engine walks steps in order.
            $table->unsignedInteger('step_order');

            // How this step decides who may act: a role, a specific user, the
            // requester's reporting manager, or a department head.
            $table->enum('approver_type', ['role', 'user', 'manager', 'department_head'])
                ->default('manager');

            $table->foreignId('approver_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('approver_employee_id')->nullable();

            $table->enum('status', ['pending', 'approved', 'rejected', 'skipped'])
                ->default('pending');

            $table->timestamp('acted_at')->nullable();
            $table->foreignId('acted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['approval_id', 'step_order']);
            // The inbox query: "my pending steps", filtered by the resolved
            // approver columns.
            $table->index(['approver_role_id', 'status']);
            $table->index(['approver_user_id', 'status']);
            $table->index('approver_employee_id');
        });
    }

    private function createAuditLogs(): void
    {
        if (Schema::hasTable('hrms_audit_logs')) {
            return;
        }

        Schema::create('hrms_audit_logs', function (Blueprint $table) {
            // `id()` only, no timestamps(): append-only. An `updated_at` would
            // invite an update and quietly turn a ledger into mutable state.
            $table->id();

            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('actor_employee_id')->nullable();

            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('action');

            // {before: {...}, after: {...}} — masked by the caller
            // (HrmsAuditLogger), never raw (D2.8).
            $table->json('data')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['actor_user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    private function createDataAccessLogs(): void
    {
        if (Schema::hasTable('hrms_data_access_logs')) {
            return;
        }

        Schema::create('hrms_data_access_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();

            // The model that was read, e.g. "Employee" or "Payslip".
            $table->string('model');
            $table->unsignedBigInteger('record_id');
            $table->enum('action', ['view', 'download', 'export'])->default('view');

            // Which fields were touched, e.g. ["salary", "bank_account"].
            $table->json('fields')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['model', 'record_id']);
            $table->index(['actor_user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    private function createSettings(): void
    {
        if (Schema::hasTable('hrms_settings')) {
            return;
        }

        Schema::create('hrms_settings', function (Blueprint $table) {
            // Singleton: every tenant has exactly one row, id = 1.
            $table->id();

            // ISO-8601: 1 = Monday.
            $table->unsignedTinyInteger('week_start')->default(1);
            $table->string('timezone', 64)->default('UTC');
            $table->string('country', 2)->nullable();
            $table->string('region')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1);
            $table->unsignedTinyInteger('leave_year_start_month')->default(1);

            $table->json('attendance')->nullable();
            $table->json('remote_clock_in')->nullable();
            $table->json('statutory')->nullable();

            $table->boolean('mask_sensitive')->default(true);
            $table->unsignedInteger('data_retention_months')->default(24);

            $table->timestamps();
        });
    }

    /**
     * Seed the singleton settings row from config.
     *
     * Runs inside the migration so a tenant that never goes through
     * `TenantProvisioner::provisionHrmsDefaults()` (P1.10 — an existing tenant
     * repaired by `tenants:provision` mid-migration) still has a usable row.
     * `updateOrInsert` is repair-safe and idempotent.
     *
     * Deliberately uses the query builder rather than the `HrmsSetting` model:
     * a migration must keep working after the model's casts/table later change,
     * and P1.9 does not create the model yet.
     *
     * **Insert-if-missing, not `updateOrInsert`.** `tenants:provision` re-runs
     * tenant migrations to repair a database, and an `updateOrInsert` would
     * reset every tenant's customised settings (currency, week_start, statutory
     * config) back to the config defaults on each repair. A seeded default must
     * never overwrite a value the tenant owns.
     */
    private function backfillSettings(): void
    {
        $defaults = config('hrms.settings_defaults', []);

        if ($defaults === []) {
            return;
        }

        if (DB::table('hrms_settings')->where('id', 1)->exists()) {
            return;
        }

        // The query builder does not honour casts, so the JSON columns are
        // encoded here rather than relying on a model.
        foreach (['attendance', 'remote_clock_in', 'statutory'] as $key) {
            if (isset($defaults[$key]) && is_array($defaults[$key])) {
                $defaults[$key] = json_encode($defaults[$key]);
            }
        }

        $now = now();

        DB::table('hrms_settings')->insert([
            'id' => 1,
            ...$defaults,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
