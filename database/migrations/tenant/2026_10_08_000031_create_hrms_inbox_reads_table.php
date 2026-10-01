<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRMS/P15.1 — the inbox read ledger.
 *
 * One row per (login, item): marking read persists per user, never
 * globally, because two people reading the same queue must never clear
 * each other's nudges. Items themselves are derived (the service merges
 * live sources), so only the reads are stored — a queue table would go
 * stale the moment any source moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inbox_reads')) {
            return;
        }

        Schema::create('inbox_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('item_key', 120);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'item_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_reads');
    }
};
