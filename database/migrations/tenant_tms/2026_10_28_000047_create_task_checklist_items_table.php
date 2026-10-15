<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('task_checklist_items')) {
            Schema::create('task_checklist_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
                $table->string('title', 255);
                $table->boolean('is_done')->default(false);
                $table->unsignedInteger('position')->default(0);
                $table->timestamp('completed_at')->nullable();
                $table->unsignedBigInteger('completed_by')->nullable();
                $table->timestamps();

                $table->index(['task_id', 'position']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_checklist_items');
    }
};
