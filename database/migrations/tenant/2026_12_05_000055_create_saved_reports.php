<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** F11.9 — saved reports: a dataset, chosen columns and filters, optionally emailed to the owner on a schedule. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('saved_reports')) {
            return;
        }

        Schema::create('saved_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('dataset', 24);              // tasks | expenses
            $table->json('columns');
            $table->json('filters')->nullable();
            $table->string('schedule', 8)->default('none'); // none | daily | weekly
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'dataset']);
            $table->index('schedule');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_reports');
    }
};
