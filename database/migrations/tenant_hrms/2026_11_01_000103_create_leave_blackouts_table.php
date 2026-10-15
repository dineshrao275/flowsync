<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRMS P5.9 — leave blackout windows.
 *
 * A blackout is a date range in which leave cannot be asked for: company-wide,
 * or narrowed to one leave type and/or one department (null = no narrowing).
 * Deleting a type/department drops the blackouts scoped to it (cascade) — a
 * blackout for a thing that no longer exists constrains nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leave_blackouts')) {
            return;
        }

        Schema::create('leave_blackouts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('from_date');
            $table->date('to_date');
            $table->foreignId('leave_type_id')->nullable()->constrained('leave_types')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->cascadeOnDelete();
            $table->string('reason', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['from_date', 'to_date']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_blackouts');
    }
};
