<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('webhook_endpoints')) {
            Schema::create('webhook_endpoints', function (Blueprint $table) {
                $table->id();
                $table->string('url', 2048);
                $table->string('description')->nullable();
                $table->text('secret'); // encrypted by the model cast
                $table->json('events'); // ["*"] or exact types / "task.*" patterns
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('disabled_at')->nullable();
                $table->string('disabled_reason')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('webhook_deliveries')) {
            Schema::create('webhook_deliveries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();
                $table->uuid('event_uuid')->index();
                $table->string('event_type', 80);
                $table->json('payload');
                $table->string('status', 16)->default('pending'); // pending|delivered|retrying|failed
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->unsignedSmallInteger('response_status')->nullable();
                $table->text('response_excerpt')->nullable();
                $table->string('error')->nullable();
                $table->timestamp('next_attempt_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamps();

                $table->index(['endpoint_id', 'id']);
                $table->index(['status', 'next_attempt_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
