<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15 — HRMS employee tables (tenant database).
 *
 *   employment_types          — the tenant's catalog of contract types. Kept as a
 *       table rather than an enum on `employees` because the set is per-tenant
 *       and tenants add their own (contractor, intern, consultant). `is_system`
 *       marks the seeded rows that may not be deleted, mirroring how project
 *       roles protect the built-in set.
 *   employees                 — the anchor record every other HRMS phase hangs
 *       off: attendance, leave, payroll, documents and assets all reference it.
 *       `user_id` is a **unique nullable** FK — a user with no employee row is a
 *       service account (a super admin, an integration bot, a login nobody pays
 *       salary to), and more than one employee may have no login at all.
 *   employee_status_history   — append-only transitions. Deliberately has **no
 *       updated_at**: a status change is never rewritten, so the table is a
 *       ledger. This is the answer to "who moved this person to notice, and
 *       when" and it must survive the employee record itself being edited.
 *
 * `department_id` / `designation_id` / `location_id` / `shift_id` arrive in P3
 * and P5 via `ALTER` in those migrations. `designation` is a plain string until
 * P3 normalizes it into a `designations` catalog.
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
        $this->createEmploymentTypes();
        $this->createEmployees();
        $this->createStatusHistory();
    }

    public function down(): void
    {
        // History cascades with its employee; the other two stand alone.
        Schema::dropIfExists('employee_status_history');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('employment_types');
    }

    private function createEmploymentTypes(): void
    {
        if (Schema::hasTable('employment_types')) {
            return;
        }

        Schema::create('employment_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();

            // Optional short label for payroll exports and payslip headers
            // ("FT", "PT", "CTR"), so it must not be required.
            $table->string('code', 32)->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);

            // Seeded rows that a tenant may rename or reorder but not delete —
            // an employee pointing at a deleted type would silently lose their
            // contract classification.
            $table->boolean('is_system')->default(false);

            $table->timestamps();

            $table->index('position');
        });
    }

    private function createEmployees(): void
    {
        if (Schema::hasTable('employees')) {
            return;
        }

        Schema::create('employees', function (Blueprint $table) {
            $table->id();

            // Unique *and* nullable: a service account has no employee record,
            // and several employees may have no login (contractors, field staff).
            // Both PostgreSQL and SQLite allow repeated NULLs in a unique index.
            // `nullOnDelete` rather than cascade — a deleted login must not take
            // the employment record, its attendance or its payslips with it.
            //
            // **`unique()` must come before `constrained()`.** `constrained()`
            // returns through `references()->on()->foreign()`, and any modifier
            // chained after it is silently dropped: the generated SQL contains
            // the foreign key but no `employees_user_id_unique` index at all, so
            // the "one login, one employee" rule would never be enforced. The
            // reverse order emits the index correctly on both grammars.
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();

            $table->string('employee_code')->unique();
            $table->string('name');
            $table->string('preferred_name')->nullable();

            // Deliberately distinct from `users.email`: this is the address the
            // employee is reachable at, which is often not their work login and
            // is personal data (P2.4 masks it for unprivileged viewers).
            $table->string('personal_email')->nullable();

            // E.164, so the column is wide enough for a country code.
            $table->string('phone', 32)->nullable();
            $table->date('date_of_birth')->nullable();

            // Free text, not enums: gender and marital status vary legally and
            // culturally by tenant, and an enum would force a migration on every
            // jurisdiction the product grows into.
            $table->string('gender', 32)->nullable();
            $table->string('marital_status', 32)->nullable();
            $table->string('nationality', 64)->nullable();

            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code', 16)->nullable();
            $table->string('country', 2)->nullable();

            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 32)->nullable();
            $table->string('emergency_contact_relation', 64)->nullable();

            $table->string('photo_path')->nullable();

            // Nullable because an offer accepted but not yet started is a real
            // state that P4 onboarding parks in until the joining date arrives.
            $table->date('joining_date')->nullable();
            $table->date('probation_end_date')->nullable();
            $table->date('confirmation_date')->nullable();

            // `nullOnDelete` as a last-resort guard; the real protection is a
            // service-level "this type is in use" refusal in P2.2, matching how
            // ProjectService blocks deleting a status that tasks reference.
            $table->foreignId('employment_type_id')
                ->nullable()
                ->constrained('employment_types')
                ->nullOnDelete();

            // Normalized into a `designations` catalog in P3.
            $table->string('designation')->nullable();

            // Self-reference to the reporting line. `nullOnDelete`, and this is
            // the important deviation from `tasks.parent_id`, which cascades:
            // cascade-on-delete would destroy every direct report when a manager
            // is removed. Orphaning a report is recoverable; losing a department
            // is not.
            $table->foreignId('manager_id')
                ->nullable()
                ->constrained('employees')
                ->nullOnDelete();

            $table->enum('work_mode', ['office', 'hybrid', 'remote'])->default('office');
            $table->enum('status', ['active', 'probation', 'on_notice', 'suspended', 'exited', 'terminated'])
                ->default('active');

            $table->date('exit_date')->nullable();
            $table->string('exited_reason')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('joining_date');
            // The reporting-line walk ("everyone under X") plus the manager_id
            // filter on the directory.
            $table->index('manager_id');
            $table->index('name');

            // department_id / location_id / shift_id are added in P3 and P5
            // together with their indexes; indexing a column that does not exist
            // yet is not possible, and a migration per column is not worth it.
        });
    }

    private function createStatusHistory(): void
    {
        if (Schema::hasTable('employee_status_history')) {
            return;
        }

        Schema::create('employee_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            // Nullable: an employee's *initial* status has no prior value, and a
            // NOT NULL here would force the service to invent a sentinel to
            // record how the record was created.
            $table->enum('from_status', ['active', 'probation', 'on_notice', 'suspended', 'exited', 'terminated'])
                ->nullable();
            $table->enum('to_status', ['active', 'probation', 'on_notice', 'suspended', 'exited', 'terminated']);

            // Nullable because a change can be back-dated (payroll correction)
            // or forward-dated (a notice period starting next month); the service
            // defaults it to the change date.
            $table->date('effective_date')->nullable();

            $table->string('reason')->nullable();
            $table->text('note')->nullable();

            // A ledger row must outlive the user who made it, so a deleted
            // account leaves the trail intact rather than erasing it.
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();

            // created_at only: append-only, like the other two ledgers.
            $table->timestamp('created_at')->nullable();

            // "Show me this employee's timeline", which is the only read path.
            $table->index(['employee_id', 'created_at']);
            $table->index('actor_user_id');
        });
    }
};
