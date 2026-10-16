<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F8 (M-C1) — skills catalogue and per-employee skill levels.
 *
 * `employee_skills.level` is 1-5 and entered by a human: either the employee's own declaration
 * (`source = self`) or a talent manager's assessment (`source = assessed`). Nothing is averaged
 * into a person-score; the matrix is a lookup grid, like the rest of talent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('skills')) {
            Schema::create('skills', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120)->unique();
                $table->string('category', 80)->nullable();
                $table->string('description', 500)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('employee_skills')) {
            Schema::create('employee_skills', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->foreignId('skill_id')->constrained('skills')->cascadeOnDelete();
                $table->unsignedTinyInteger('level');
                $table->string('source', 10)->default('self');
                $table->unsignedBigInteger('assessed_by_user_id')->nullable();
                $table->timestamp('assessed_at')->nullable();
                $table->string('note', 255)->nullable();
                $table->timestamps();

                $table->unique(['employee_id', 'skill_id']);
                $table->index('skill_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_skills');
        Schema::dropIfExists('skills');
    }
};
