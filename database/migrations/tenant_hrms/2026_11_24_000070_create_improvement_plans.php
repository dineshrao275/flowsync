<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** F8 — performance improvement plans: a dated plan with milestones, closed with an outcome. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('improvement_plans')) {
            return;
        }

        Schema::create('improvement_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->text('reason');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 16)->default('active'); // active | completed | unsuccessful | cancelled
            $table->json('milestones')->nullable();           // [{id, title, due_date, done}]
            $table->text('outcome')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('improvement_plans');
    }
};
