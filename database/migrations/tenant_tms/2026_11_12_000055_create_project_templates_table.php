<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F4.6 — project templates: a reusable snapshot of a project's workflow (statuses, allowed
 * transitions, entry rules, WIP limits), components and, optionally, starter tasks. Tenant-wide;
 * the snapshot is plain JSON keyed by status slug so it does not depend on row ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_templates')) {
            return;
        }

        Schema::create('project_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('snapshot');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_templates');
    }
};
