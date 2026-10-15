<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant's domain event outbox: one row per business fact ("task.completed"),
 * written where the change is made and handed to consumers (outbound webhooks today,
 * automation next) afterwards. Append-only; `processed_at` marks delivery to consumers.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('domain_events')) {
            return;
        }

        Schema::create('domain_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 80)->index();
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_events');
    }
};
