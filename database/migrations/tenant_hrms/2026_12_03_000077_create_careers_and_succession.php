<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F8 — career paths (which designation leads to which; visible to everyone) and succession plans (key roles
 * with named successors and a readiness, confidential to talent managers).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('career_paths')) {
            Schema::create('career_paths', function (Blueprint $table) {
                $table->id();
                $table->foreignId('from_designation_id')->constrained('designations')->cascadeOnDelete();
                $table->foreignId('to_designation_id')->constrained('designations')->cascadeOnDelete();
                $table->string('note', 300)->nullable();
                $table->timestamps();

                $table->unique(['from_designation_id', 'to_designation_id']);
            });
        }

        if (! Schema::hasTable('succession_positions')) {
            Schema::create('succession_positions', function (Blueprint $table) {
                $table->id();
                $table->string('title', 160);
                $table->foreignId('incumbent_employee_id')->nullable()->constrained('employees')->nullOnDelete();
                $table->string('risk', 8)->default('medium'); // low | medium | high — how much the loss would hurt
                $table->string('note', 500)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('succession_candidates')) {
            Schema::create('succession_candidates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('position_id')->constrained('succession_positions')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->string('readiness', 12); // now | one_two_years | three_plus
                $table->string('note', 300)->nullable();
                $table->timestamps();

                $table->unique(['position_id', 'employee_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('succession_candidates');
        Schema::dropIfExists('succession_positions');
        Schema::dropIfExists('career_paths');
    }
};
