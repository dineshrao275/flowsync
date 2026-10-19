<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** F2.6 — one comment thread table shared by HR records (expense claims, leave requests, …). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('record_comments')) {
            return;
        }

        Schema::create('record_comments', function (Blueprint $table) {
            $table->id();
            $table->string('commentable_type', 64);
            $table->unsignedBigInteger('commentable_id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->nullable();

            $table->index(['commentable_type', 'commentable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_comments');
    }
};
