<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — tenant-local export_runs table.
 *
 * Tracks queued full-export jobs so a user can poll status and fetch the
 * signed ZIP download link. One row per requested export; old rows cleaned
 * up by the ExportJob after TTL (or a separate pruning command later).
 *
 * All rows are scoped to this tenant DB (no tenant_id column — isolation is
 * the physical database). The central audit log gets a companion row via
 * HrmsAuditLogger::accessed(..., Export) in ExportController::download().
 */
return new class extends Migration
{
    public $withinTransaction = false; // allow CREATE INDEX CONCURRENTLY on PG

    public function up(): void
    {
        if (Schema::hasTable('export_runs')) {
            return;
        }

        Schema::create('export_runs', function (Blueprint $table) {
            $table->id();

            // The tenant-local user who requested the export. Nulls if the
            // user is later deleted; we keep the run row for the audit trail.
            $table->unsignedBigInteger('user_id')->nullable()->index();

            // Comma-separated category keys requested (workspaces,projects,tasks,
            // employees,…). Stored as JSON so we can query without a pivot table.
            $table->json('categories');

            // pending → processing → ready | failed
            $table->string('status', 20)->default('pending')->index();

            // Absolute path inside storage/app/private for the finished ZIP.
            // Null until the job completes successfully.
            $table->string('file_path')->nullable();

            // File size in bytes (set on completion, shown in the UI).
            $table->unsignedBigInteger('file_size')->nullable();

            // Error message when status = failed.
            $table->text('error_message')->nullable();

            // When the signed download expires (default +1 hour from ready).
            $table->timestamp('expires_at')->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_runs');
    }
};
