<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** F8 — OKRs: an objective (company-wide when it has no owner) with measurable key results. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('okr_objectives')) {
            Schema::create('okr_objectives', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->nullable()->constrained('employees')->cascadeOnDelete(); // null = company objective
                $table->foreignId('parent_id')->nullable()->constrained('okr_objectives')->nullOnDelete();  // alignment upward
                $table->string('title', 200);
                $table->string('period', 32); // e.g. "Q4 2026"
                $table->date('start_date');
                $table->date('end_date');
                $table->string('status', 12)->default('active'); // active | closed
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();

                $table->index(['employee_id', 'status']);
            });
        }

        if (! Schema::hasTable('okr_key_results')) {
            Schema::create('okr_key_results', function (Blueprint $table) {
                $table->id();
                $table->foreignId('objective_id')->constrained('okr_objectives')->cascadeOnDelete();
                $table->string('title', 200);
                $table->decimal('start_value', 14, 2)->default(0);
                $table->decimal('target_value', 14, 2);
                $table->decimal('current_value', 14, 2)->default(0);
                $table->string('unit', 24)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('okr_key_results');
        Schema::dropIfExists('okr_objectives');
    }
};
