<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The repositories an installation grants access to (mirrored; rows are never deleted, only marked unavailable). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('git_repositories')) {
            return;
        }

        Schema::create('git_repositories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('git_connection_id')->constrained('git_connections')->cascadeOnDelete();
            $table->string('provider', 20)->default('github');
            $table->unsignedBigInteger('provider_repo_id');
            $table->string('full_name', 255);
            $table->string('name', 150);
            $table->string('owner', 120);
            $table->string('default_branch', 150)->nullable();
            $table->boolean('private')->default(true);
            $table->boolean('archived')->default(false);
            $table->boolean('disabled')->default(false);
            $table->boolean('available')->default(true);
            $table->string('html_url', 500)->nullable();
            $table->string('clone_url', 500)->nullable();
            $table->string('ssh_url', 500)->nullable();
            $table->json('permissions')->nullable();
            $table->timestamp('pushed_at')->nullable();
            $table->timestamp('provider_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['git_connection_id', 'provider_repo_id']);
            $table->index(['git_connection_id', 'available']);
            $table->index('full_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('git_repositories');
    }
};
