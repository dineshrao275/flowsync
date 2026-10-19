<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** F8 — individual development plans: growth goals with dated actions, owned by the employee. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('development_plans')) {
            return;
        }

        Schema::create('development_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->string('title', 200);
            $table->text('focus')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 12)->default('active'); // active | completed | cancelled
            $table->json('actions')->nullable();             // [{id, title, due_date, done}]
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('development_plans');
    }
};
