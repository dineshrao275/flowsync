<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F7.1 — shift swaps: two people trade their shifts for one day. The counterparty agrees first, then the
 * requester's manager; on the final approval both days are rewritten as single-day roster rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shift_swaps')) {
            return;
        }

        Schema::create('shift_swaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('counterparty_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('swap_date');
            $table->unsignedBigInteger('requester_shift_id')->nullable(); // snapshot; null = day off
            $table->unsignedBigInteger('counterparty_shift_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('status', 16)->default('pending'); // pending | applied | rejected | withdrawn
            $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'swap_date']);
            $table->index('requester_employee_id');
            $table->index('counterparty_employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_swaps');
    }
};
