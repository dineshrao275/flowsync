<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('task_views')) {
            return;
        }

        Schema::create('task_views', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('last_seen_at')->nullable();

            $table->primary(['task_id', 'user_id']);
            $table->index(['task_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_views');
    }
};
