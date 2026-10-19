<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F6.3 — transfers, promotions and confirmations: an effective-dated change to an employee's record that
 * goes through the shared approval engine (domain `employee_change`) and is applied on its date.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_changes')) {
            return;
        }

        Schema::create('employee_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('type', 16); // transfer | promotion | confirmation
            $table->date('effective_date');
            $table->json('changes')->nullable();
            $table->text('reason')->nullable();
            $table->string('status', 16)->default('pending'); // pending | approved | rejected | withdrawn | applied
            $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->unsignedBigInteger('decided_by_user_id')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['status', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_changes');
    }
};
