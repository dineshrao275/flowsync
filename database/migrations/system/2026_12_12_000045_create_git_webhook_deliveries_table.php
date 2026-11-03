<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One row per inbound provider delivery: the replay guard and the platform health log. No code or tokens are stored. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('git_webhook_deliveries')) {
            return;
        }

        Schema::create('git_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('delivery_uuid', 64)->unique();
            $table->unsignedBigInteger('git_connection_id')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('event', 60);
            $table->string('action', 60)->nullable();
            $table->string('status', 20)->default('received');
            $table->text('error')->nullable();
            $table->unsignedInteger('payload_bytes')->default(0);
            $table->string('payload_excerpt', 255)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'received_at']);
            $table->index(['git_connection_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('git_webhook_deliveries');
    }
};
