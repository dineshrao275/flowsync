<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('support_tickets')) {
            Schema::create('support_tickets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                // Who raised it: id inside the tenant DB (like every id in there) plus a snapshot.
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->string('created_by_name');
                $table->string('created_by_email');
                $table->string('subject', 255);
                $table->string('category', 32)->default('other');
                $table->string('priority', 16)->default('normal');
                $table->string('status', 24)->default('open');
                // Platform staff (central users) working it.
                $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('last_activity_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'status']);
                $table->index(['status', 'priority']);
                $table->index('last_activity_at');
            });
        }

        if (! Schema::hasTable('support_ticket_messages')) {
            Schema::create('support_ticket_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
                $table->string('author_type', 16); // tenant | staff
                $table->unsignedBigInteger('author_user_id')->nullable();
                $table->string('author_name');
                $table->text('body');
                // Internal notes are staff-only and never reach the tenant.
                $table->boolean('is_internal')->default(false);
                $table->timestamps();

                $table->index(['ticket_id', 'id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};
