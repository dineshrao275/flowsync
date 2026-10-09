<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** P2.7 — personal API tokens, idempotency keys and the integration (API call) log. Repair-safe. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('api_tokens')) {
            Schema::create('api_tokens', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('name', 120);
                $table->string('token_hash', 64)->unique(); // sha256 of the plaintext; the plaintext is never stored
                $table->string('token_hint', 12);           // last characters, for recognising a token in a list
                $table->json('abilities');                  // permission slugs, or ["*"]
                $table->boolean('can_write')->default(false);
                $table->unsignedSmallInteger('rate_limit')->nullable(); // per minute; null = config default
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->string('last_used_ip', 45)->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('api_idempotency_keys')) {
            Schema::create('api_idempotency_keys', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('token_id')->index();
                $table->string('key', 120);
                $table->string('fingerprint', 64);          // sha256(method + path + body)
                $table->unsignedSmallInteger('response_status')->nullable(); // null while the first call is still running
                $table->longText('response_body')->nullable();
                $table->timestamps();

                $table->unique(['token_id', 'key']);
            });
        }

        if (! Schema::hasTable('integration_logs')) {
            Schema::create('integration_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('token_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('method', 8);
                $table->string('path', 255);
                $table->unsignedSmallInteger('status');
                $table->unsignedInteger('duration_ms');
                $table->string('ip', 45)->nullable();
                $table->string('request_id', 64)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['token_id', 'id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_logs');
        Schema::dropIfExists('api_idempotency_keys');
        Schema::dropIfExists('api_tokens');
    }
};
