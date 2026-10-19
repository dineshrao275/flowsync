<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** F4.1 — moves into a status that requires approval wait here until a workflow manager decides. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('task_move_requests')) {
            return;
        }

        Schema::create('task_move_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unsignedBigInteger('from_status_id')->nullable();
            $table->foreignId('to_status_id')->constrained('task_statuses')->cascadeOnDelete();
            $table->unsignedInteger('position')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->string('status', 12)->default('pending'); // pending | approved | rejected | withdrawn
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['task_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_move_requests');
    }
};
