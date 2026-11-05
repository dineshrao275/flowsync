<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central application error log (Super Admin → Errors).
 *
 * One rollup row per fingerprint (`error_logs`) plus one row per event
 * (`error_occurrences`). Notes and status transitions are their own ledgers so
 * triage history is append-only. Lives in the system DB: an application error
 * is platform telemetry, never a tenant-domain record, and staff must be able
 * to read it without opening a tenant database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('error_logs')) {
            Schema::create('error_logs', function (Blueprint $table) {
                $table->id();
                $table->string('fingerprint', 64)->unique();
                $table->string('exception_class', 255)->nullable();
                $table->text('message');
                $table->string('severity', 16)->default('error');
                $table->string('status', 20)->default('open');
                $table->string('module', 64)->nullable();
                $table->string('source', 16)->default('backend'); // backend|queue|console|frontend
                $table->string('environment', 32)->nullable();
                // No FK: deleting a tenant must never delete the evidence about it.
                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->string('request_id', 64)->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('path', 500)->nullable();
                $table->string('method', 10)->nullable();
                $table->unsignedSmallInteger('http_status')->nullable();
                $table->string('file', 500)->nullable();
                $table->unsignedInteger('line')->nullable();
                $table->mediumText('stack_trace')->nullable();
                $table->json('context')->nullable();
                $table->unsignedInteger('occurrences')->default(1);
                $table->timestamp('first_seen_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->unsignedBigInteger('resolved_by')->nullable();
                $table->timestamp('ignored_at')->nullable();
                $table->timestamps();

                $table->index('status');
                $table->index('severity');
                $table->index('module');
                $table->index('source');
                $table->index('tenant_id');
                $table->index('last_seen_at');
                $table->index('first_seen_at');
                $table->index(['status', 'last_seen_at']);
            });
        }

        if (! Schema::hasTable('error_occurrences')) {
            Schema::create('error_occurrences', function (Blueprint $table) {
                $table->id();
                $table->foreignId('error_log_id')->constrained('error_logs')->cascadeOnDelete();
                $table->timestamp('occurred_at');
                $table->string('request_id', 64)->nullable();
                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('path', 500)->nullable();
                $table->string('method', 10)->nullable();
                $table->unsignedSmallInteger('http_status')->nullable();
                $table->json('context')->nullable();
                $table->timestamps();

                $table->index(['error_log_id', 'occurred_at']);
                $table->index('occurred_at');
                $table->index('tenant_id');
            });
        }

        if (! Schema::hasTable('error_log_notes')) {
            Schema::create('error_log_notes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('error_log_id')->constrained('error_logs')->cascadeOnDelete();
                $table->unsignedBigInteger('author_id')->nullable();
                $table->string('author_name', 255)->nullable();
                $table->text('body');
                $table->timestamps();

                $table->index(['error_log_id', 'id']);
            });
        }

        if (! Schema::hasTable('error_log_status_history')) {
            Schema::create('error_log_status_history', function (Blueprint $table) {
                $table->id();
                $table->foreignId('error_log_id')->constrained('error_logs')->cascadeOnDelete();
                $table->string('from_status', 20)->nullable();
                $table->string('to_status', 20);
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('actor_name', 255)->nullable();
                $table->text('note')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['error_log_id', 'id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('error_log_status_history');
        Schema::dropIfExists('error_log_notes');
        Schema::dropIfExists('error_occurrences');
        Schema::dropIfExists('error_logs');
    }
};
