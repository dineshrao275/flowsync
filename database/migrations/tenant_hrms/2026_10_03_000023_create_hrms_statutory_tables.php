<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRMS/P10.1 — the statutory compliance tables.
 *
 * Four tables, each repair-safe (`hasTable` guards — `tenants:provision`
 * re-runs migrations that failed partway):
 *
 *   statutory_configurations — jurisdiction presets copied into tenant-owned
 *     rows. The engine reads these rows, never `config/hrms.php` (D2.9): a
 *     preset is a starting point a statutory expert confirms, not truth.
 *   statutory_profiles       — one PII row per employee (unique), owned rows
 *     that cascade with the employee so no orphan PII outlives its person.
 *   statutory_declarations   — per-fiscal-year exemption claims with an
 *     optional proof file (orphaned link, never the row).
 *   tds_projects              — quarterly TDS projections, one per
 *     (employee, year, quarter).
 *
 * Numbered `000023`: the plan's Part 3.3 slot, still free — the settings
 * follow-ups already took 000024/000025, so P11/P12 will need new numbers.
 * Dated after the document tables because declarations reference
 * `employee_documents`, which only exists from `000026` on.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createStatutoryConfigurations();
        $this->createStatutoryProfiles();
        $this->createStatutoryDeclarations();
        $this->createTdsProjections();
    }

    public function down(): void
    {
        Schema::dropIfExists('tds_projects');
        Schema::dropIfExists('statutory_declarations');
        Schema::dropIfExists('statutory_profiles');
        Schema::dropIfExists('statutory_configurations');
    }

    private function createStatutoryConfigurations(): void
    {
        if (Schema::hasTable('statutory_configurations')) {
            return;
        }

        Schema::create('statutory_configurations', function (Blueprint $table) {
            $table->id();
            $table->char('country', 2);
            $table->string('region')->nullable();
            $table->string('name');
            $table->string('code')->unique();
            $table->boolean('is_active')->default(true);
            $table->json('config')->nullable();
            $table->timestamps();

            $table->index(['country', 'region']);
        });
    }

    private function createStatutoryProfiles(): void
    {
        if (Schema::hasTable('statutory_profiles')) {
            return;
        }

        Schema::create('statutory_profiles', function (Blueprint $table) {
            $table->id();

            // Owned rows: deleting the employment record takes its PII with
            // it, so no orphan identifiers outlive their person. The access
            // ledger (model + id) survives as the trail.
            $table->foreignId('employee_id')->unique()->constrained('employees')->cascadeOnDelete();

            $table->string('pan')->nullable();
            $table->string('aadhaar_last4', 4)->nullable();
            $table->string('uan')->nullable();
            $table->string('esi_number')->nullable();
            $table->string('pf_number')->nullable();
            $table->string('pt_state')->nullable();
            $table->boolean('lwf_registration')->default(false);
            $table->string('bank_name')->nullable();
            $table->text('bank_account_encrypted')->nullable();
            $table->string('bank_ifsc')->nullable();
            $table->json('tax_declaration')->nullable();
            $table->json('declarations')->nullable();

            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    private function createStatutoryDeclarations(): void
    {
        if (Schema::hasTable('statutory_declarations')) {
            return;
        }

        Schema::create('statutory_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->string('section');
            $table->decimal('declared_amount', 14, 2)->default(0);

            // A deleted proof orphans the link while the claim stays
            // readable — the declaration is the record, the file its evidence.
            $table->foreignId('proof_document_id')->nullable()->constrained('employee_documents')->nullOnDelete();

            $table->enum('status', ['draft', 'submitted', 'verified', 'rejected'])->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['employee_id', 'fiscal_year']);
        });
    }

    private function createTdsProjections(): void
    {
        if (Schema::hasTable('tds_projects')) {
            return;
        }

        Schema::create('tds_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedTinyInteger('quarter');
            $table->decimal('declared_income', 14, 2)->default(0);
            $table->decimal('exempt_income', 14, 2)->default(0);
            $table->decimal('projected_income', 14, 2)->default(0);
            $table->decimal('tax_liability', 14, 2)->default(0);
            $table->decimal('tds_deducted', 14, 2)->default(0);
            $table->decimal('tds_surrendered', 14, 2)->default(0);
            $table->string('challan_ref')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'fiscal_year', 'quarter']);
        });
    }
};
