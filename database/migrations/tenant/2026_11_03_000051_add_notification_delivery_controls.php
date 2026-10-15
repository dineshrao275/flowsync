<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2.9 — notification dedup key, per-user email mode + language, the digest queue and the
 * tenant channel policy. Plain columns/indexes only (no foreign keys on existing tables), so
 * the sqlite fast-path never rebuilds a table. Repair-safe: every step is guarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notifications') && ! Schema::hasColumn('notifications', 'dedupe_key')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->string('dedupe_key', 40)->nullable();
                $table->index(['user_id', 'dedupe_key']);
            });
        }

        if (Schema::hasTable('notification_preferences')) {
            if (! Schema::hasColumn('notification_preferences', 'email_mode')) {
                Schema::table('notification_preferences', function (Blueprint $table) {
                    $table->string('email_mode', 12)->default('instant'); // instant | digest
                });
            }
            if (! Schema::hasColumn('notification_preferences', 'locale')) {
                Schema::table('notification_preferences', function (Blueprint $table) {
                    $table->string('locale', 8)->nullable();
                });
            }
        }

        if (! Schema::hasTable('notification_digest_items')) {
            Schema::create('notification_digest_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('type', 80);
                $table->string('actor_name')->nullable();
                $table->json('data')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('sent_at')->nullable();

                $table->index(['sent_at', 'user_id']);
            });
        }

        if (! Schema::hasTable('notification_policies')) {
            Schema::create('notification_policies', function (Blueprint $table) {
                $table->id();
                $table->string('event', 80);
                $table->string('channel', 16)->default('email');
                $table->boolean('enabled')->default(true);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['event', 'channel']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_policies');
        Schema::dropIfExists('notification_digest_items');
    }
};
