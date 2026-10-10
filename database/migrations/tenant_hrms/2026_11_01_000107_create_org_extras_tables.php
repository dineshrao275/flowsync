<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRMS P5.13 — enterprise org modelling beyond the department tree.
 *
 *  - `org_units`: typed nodes (business unit, legal entity, cost centre) in
 *    their own small trees. They are *separate* from departments on purpose: a
 *    legal entity is a different axis from a reporting department, and folding
 *    them into one tree forces one of the two to lie.
 *  - `employee_org_units`: which unit a person sits in, at most one per type
 *    (the unique key is employee + type — an employee has one cost centre).
 *  - `employee_dotted_lines`: the second reporting edge. `employees.manager_id`
 *    stays the single line of record (approvals, payroll, leave all follow it);
 *    a dotted line is a functional/project relationship that is *visible* but
 *    never approves anything, so it needs no cycle check.
 *
 * Only `Schema::create` here — no ALTER of existing tables, so nothing to lose in
 * a SQLite rebuild — and each table is `hasTable`-guarded for repair re-runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('org_units')) {
            Schema::create('org_units', function (Blueprint $table) {
                $table->id();
                $table->string('type', 24);
                $table->string('name');
                $table->string('code', 64);
                $table->foreignId('parent_id')->nullable()->constrained('org_units')->nullOnDelete();
                $table->foreignId('head_employee_id')->nullable()->constrained('employees')->nullOnDelete();
                $table->string('description', 500)->nullable();
                $table->json('meta')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();

                $table->unique(['type', 'code']);
                $table->index('type');
            });
        }

        if (! Schema::hasTable('employee_org_units')) {
            Schema::create('employee_org_units', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->foreignId('org_unit_id')->constrained('org_units')->cascadeOnDelete();
                $table->string('unit_type', 24);
                $table->timestamps();

                $table->unique(['employee_id', 'unit_type']);
                $table->index('org_unit_id');
            });
        }

        if (! Schema::hasTable('employee_dotted_lines')) {
            Schema::create('employee_dotted_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->foreignId('manager_id')->constrained('employees')->cascadeOnDelete();
                $table->string('kind', 16)->default('dotted');
                $table->string('note', 255)->nullable();
                $table->date('effective_from')->nullable();
                $table->date('effective_to')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['employee_id', 'manager_id', 'kind']);
                $table->index('manager_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_dotted_lines');
        Schema::dropIfExists('employee_org_units');
        Schema::dropIfExists('org_units');
    }
};
