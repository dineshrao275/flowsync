<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRMS/P14.1 — the asset tracking tables.
 *
 * Four repair-safe tables (`hasTable` guards — `tenants:provision` re-runs
 * migrations that failed partway):
 *
 *   asset_categories  — the tenant's register catalogue (created over HTTP
 *     in P14.3; no starters are seeded, because a register starts empty
 *     and every tenant's hardware differs).
 *   assets            — the register itself, soft-deleted so a retired
 *     laptop keeps its history. Assignment state lives here (`status`,
 *     `assigned_to_employee_id`) for the at-a-glance read; the ledger of
 *     who held what lives in `asset_assignments`.
 *   asset_assignments — one row per handover, unique per (asset, employee,
 *     moment). The offboarding clearance reads the `active` rows.
 *   asset_maintenance — the repair log. An open record parks the asset in
 *     `maintenance`; closing the loop returns it to service.
 *
 * Owned rows cascade with their asset; catalogue, file, location and user
 * links null so pruning any of them orphans the link, never the record.
 * A category with assets behind it is refused deletion in the service,
 * with the database backstopping instead of cascading history away (the
 * salary-structure precedent) — hence plain `constrained()` (NO ACTION)
 * on `assets.category_id`.
 *
 * Numbered `000030`: the plan's `000027`/`000028` are both spent (expenses,
 * performance tables) — pre-assigned numbers are advisory once follow-ups
 * spend them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createAssetCategories();
        $this->createAssets();
        $this->createAssetAssignments();
        $this->createAssetMaintenance();
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_maintenance');
        Schema::dropIfExists('asset_assignments');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('asset_categories');
    }

    private function createAssetCategories(): void
    {
        if (Schema::hasTable('asset_categories')) {
            return;
        }

        Schema::create('asset_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->enum('default_condition', ['new', 'good', 'fair', 'poor'])->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });
    }

    private function createAssets(): void
    {
        if (Schema::hasTable('assets')) {
            return;
        }

        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_code')->unique();
            $table->string('name');
            $table->foreignId('category_id')->constrained('asset_categories');
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable()->unique();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_value', 14, 2)->nullable();
            $table->string('vendor')->nullable();
            $table->foreignId('invoice_document_id')->nullable()->constrained('employee_documents')->nullOnDelete();
            $table->date('warranty_ends_at')->nullable();
            $table->enum('condition', ['new', 'good', 'fair', 'poor', 'damaged'])->default('good');
            $table->enum('status', ['available', 'assigned', 'maintenance', 'retired', 'lost'])->default('available');
            $table->foreignId('assigned_to_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'assigned_to_employee_id']);
        });
    }

    private function createAssetAssignments(): void
    {
        if (Schema::hasTable('asset_assignments')) {
            return;
        }

        Schema::create('asset_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->enum('condition_out', ['new', 'good', 'fair', 'poor', 'damaged'])->default('good');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->enum('condition_in', ['new', 'good', 'fair', 'poor', 'damaged'])->nullable();
            $table->text('return_note')->nullable();
            $table->enum('status', ['active', 'returned', 'lost', 'damaged'])->default('active');
            $table->timestamps();

            $table->unique(['asset_id', 'employee_id', 'assigned_at']);
        });
    }

    private function createAssetMaintenance(): void
    {
        if (Schema::hasTable('asset_maintenance')) {
            return;
        }

        Schema::create('asset_maintenance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->enum('type', ['repair', 'service', 'upgrade', 'inspection'])->default('service');
            $table->text('description');
            $table->string('performed_by')->nullable();
            $table->decimal('cost', 14, 2)->nullable();
            $table->date('performed_at');
            $table->date('next_due_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('asset_id');
        });
    }
};
