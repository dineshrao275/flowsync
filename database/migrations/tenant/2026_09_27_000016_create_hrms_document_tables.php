<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15 — HRMS employee documents (tenant database).
 *
 * Numbered 000016, out of chronological order, on purpose: five later
 * files (lifecycle, leave, comp-off, payroll, statutory) hold foreign
 * keys into these two tables, and PostgreSQL validates the referenced
 * table at CREATE time — a later number breaks every fresh provision
 * with `relation "document_types" does not exist`, while SQLite
 * silently tolerates the forward reference (which is why the suite
 * never caught it). Renaming is safe for migrated databases: every
 * create here is hasTable-guarded, so the file re-runs as a no-op.
 *   document_types     — the tenant's document catalogue: a passport is
 *       mandatory everywhere, a work visa is mandatory for nobody. `is_system`
 *       is what stops a type payroll or a compliance report already relies on
 *       from being deleted out from under them.
 *   employee_documents — one stored file per row, with a lifecycle of its own:
 *       uploaded → verified → (rejected | expired). Deliberately **not** a
 *       status derived from `expires_at`: expiry is a fact the compliance
 *       report needs to be able to assert, and a row whose status changes with
 *       the clock is a row nobody can audit.
 *
 * **Files live on the `local` disk under `hrms/{tenant_id}/{employee_id}/`.**
 * The tenant segment is redundant with the database boundary (Phase 13) and is
 * kept anyway: it makes a filesystem-level disaster (a restored dump, a
 * hand-run `find`) survivable, because one tenant's files can be identified
 * without a database join. `Storage` is configured with a private root, so
 * nothing here is publicly addressable — the only way in is the signed route in
 * P13.2.
 *
 * **`expires_at` is indexed on its own, and indexed with `status`.** "What
 * expires in the next 30 days" is the query the compliance panel runs, and it
 * runs it for every employee on the page. An index on `expires_at` alone
 * narrows to the whole-history tail before the status filter is applied; the
 * pair is what the query actually reads.
 *
 * **`visibility` and `confidential` are separate columns, deliberately.**
 * `visibility` is *who* may see the row (the employee themself, HR, a manager,
 * the document's owner); `confidential` is *how* sensitive the document is and
 * gates the extra `hrms.documents.view_sensitive` permission. Collapsing them
 * into one field means every future "only HR, not the manager" rule is a data
 * migration, and "bank details" cannot be expressed at all without a second
 * field anyway.
 *
 * **No `tenant_id` column** — the tenant database is the isolation boundary
 * (Phase 13).
 *
 * Repair-safe and every step independent: `tenants:provision` re-runs tenant
 * migrations to repair a database that failed partway, and a partial run must
 * be completable. Each table is created only when absent, so the same
 * "guard each step on its own" discipline as
 * `TenantProvisioner::provisionHrmsDefaults()` applies.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createDocumentTypes();
        $this->createEmployeeDocuments();
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
        Schema::dropIfExists('document_types');
    }

    private function createDocumentTypes(): void
    {
        if (Schema::hasTable('document_types')) {
            return;
        }

        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // Unique per tenant: two types that differ only in case or spacing
            // are one type as far as anybody picking from a list is concerned.
            $table->string('slug')->unique();

            // A family, not a form field. "identity" is the group a compliance
            // report and the P18 completeness check group by; the individual
            // documents are the rows. Free text would make that report a LIKE
            // search over prose, and a typo in a category would silently drop a
            // document out of a statutory return.
            $table->enum('category', [
                'identity', 'education', 'employment', 'tax', 'bank', 'medical', 'asset', 'letter', 'other',
            ]);

            $table->boolean('is_mandatory')->default(false);

            // An expiry date that nothing tracks is a date nobody trusts, so
            // `requires_expiry` pairs with the `hrms:documents-expiry` command in
            // P13.2: a type that requires expiry is one the command will act on.
            $table->boolean('requires_expiry')->default(false);

            // How long after `expires_at` (or after upload, for a document with
            // no expiry) the file is retained. Null means "keep indefinitely",
            // which is the honest default for a tax declaration and a very
            // different promise from a five-year one.
            $table->unsignedInteger('retention_months')->nullable();

            $table->boolean('is_sensitive')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);

            $table->timestamps();

            $table->index('category');
            $table->index('position');
            $table->index('is_active');
        });
    }

    private function createEmployeeDocuments(): void
    {
        if (Schema::hasTable('employee_documents')) {
            return;
        }

        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('document_type_id')->nullable()->constrained('document_types')->nullOnDelete();

            // A title of its own, because the type is a category and the row is
            // a specific instance: two "Passport" rows for one person are the
            // current one and the one being replaced.
            $table->string('title');

            // Where the bytes are, and what they were called. `original_name`
            // is the name the employee recognises, which is never the name the
            // UUID storage generated.
            $table->string('file_disk')->default('local');
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();

            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();

            // `pending` until somebody in HR looks at it: a document is not
            // evidence just because it exists. `verified` is the state a payroll
            // run trusts, so nothing may reach it without a human.
            $table->enum('status', ['pending', 'verified', 'rejected', 'expired'])->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rejection_reason')->nullable();

            // See the class docblock: who may see the row, versus how sensitive
            // the document is. Two columns, not one.
            $table->enum('visibility', ['employee', 'hr', 'manager', 'owner'])->default('hr');
            $table->boolean('confidential')->default(false);

            // How the document arrived, so the onboarding checklist and the
            // expense claim can point at the file they caused. `hr` is the
            // default because an HR-entered document is the most common one.
            $table->enum('source', [
                'employee', 'hr', 'onboarding', 'offboarding', 'expense', 'asset', 'payslip',
            ])->default('hr');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // The store's main query: one employee's documents, newest first.
            $table->index(['employee_id', 'status']);
            $table->index(['employee_id', 'created_at']);

            // The compliance query — see the class docblock.
            $table->index('expires_at');
            $table->index(['status', 'expires_at']);

            $table->index('document_type_id');
            $table->index('source');
            $table->index('visibility');
        });
    }
};
