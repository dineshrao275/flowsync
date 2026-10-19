<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** F7.6 — loans and salary advances repaid by equal monthly instalments deducted through payroll. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_loans')) {
            Schema::create('employee_loans', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->string('kind', 12)->default('loan'); // loan | advance
                $table->decimal('principal', 14, 2);
                $table->unsignedSmallInteger('installments');
                $table->decimal('installment_amount', 14, 2);
                $table->unsignedSmallInteger('first_year');
                $table->unsignedTinyInteger('first_month');
                $table->decimal('balance', 14, 2);
                $table->string('reason', 500)->nullable();
                $table->string('status', 12)->default('pending'); // pending | active | closed | rejected | cancelled
                $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();
                $table->unsignedBigInteger('requested_by_user_id')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->string('decision_note', 500)->nullable();
                $table->timestamps();

                $table->index(['employee_id', 'status']);
            });
        }

        if (! Schema::hasTable('loan_installments')) {
            Schema::create('loan_installments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_loan_id')->constrained('employee_loans')->cascadeOnDelete();
                $table->unsignedSmallInteger('number');
                $table->unsignedSmallInteger('due_year');
                $table->unsignedTinyInteger('due_month');
                $table->decimal('amount', 14, 2);
                $table->string('status', 8)->default('due'); // due | paid
                $table->unsignedBigInteger('payroll_run_id')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();

                $table->unique(['employee_loan_id', 'number']);
                $table->index(['status', 'due_year', 'due_month']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_installments');
        Schema::dropIfExists('employee_loans');
    }
};
