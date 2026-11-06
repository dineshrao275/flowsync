<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI assistant data. Tenant core (not TMS) because the assistant also serves HRMS-only tenants;
 * project/task ids are plain integers so a tenant without the TMS schema still migrates.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_conversations')) {
            Schema::create('ai_conversations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('title', 160)->nullable();
                $table->string('product', 10)->nullable();
                $table->timestamp('last_message_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'last_message_at']);
            });
        }

        if (! Schema::hasTable('ai_messages')) {
            Schema::create('ai_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
                $table->string('role', 12);
                $table->text('content')->nullable();
                $table->string('tool_name', 60)->nullable();
                $table->json('payload')->nullable();
                $table->unsignedInteger('input_tokens')->default(0);
                $table->unsignedInteger('output_tokens')->default(0);
                $table->timestamps();

                $table->index(['conversation_id', 'id']);
            });
        }

        if (! Schema::hasTable('ai_documents')) {
            Schema::create('ai_documents', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('project_id')->nullable();
                $table->string('original_name', 255);
                $table->string('mime', 120)->nullable();
                $table->unsignedBigInteger('size')->default(0);
                $table->string('path', 255);
                $table->string('status', 16)->default('uploaded');
                $table->string('error', 255)->nullable();
                $table->unsignedInteger('pages')->default(0);
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'status']);
                $table->index('expires_at');
            });
        }

        if (! Schema::hasTable('ai_proposals')) {
            Schema::create('ai_proposals', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignId('conversation_id')->nullable()->constrained('ai_conversations')->nullOnDelete();
                $table->unsignedBigInteger('user_id');
                $table->string('kind', 40);
                $table->unsignedBigInteger('project_id')->nullable();
                $table->unsignedBigInteger('document_id')->nullable();
                $table->json('payload');
                $table->json('result')->nullable();
                $table->string('status', 16)->default('draft');
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_proposals');
        Schema::dropIfExists('ai_documents');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
    }
};
