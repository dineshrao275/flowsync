<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F6.4 — an employee's own resignation, approved through the shared approval engine (domain
 * `resignation`); approval opens the exit (offboarding) case, which this row links to.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('resignation_requests')) {
            return;
        }

        Schema::create('resignation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('requested_last_day');
            $table->unsignedSmallInteger('notice_period_days')->nullable();
            $table->text('reason');
            $table->string('status', 16)->default('pending');
            $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->unsignedBigInteger('decided_by_user_id')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->unsignedBigInteger('offboarding_case_id')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resignation_requests');
    }
};
