<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A repository linked to a project. The connection and credentials live in the system DB; this row only
 * remembers which repository (by its connection uuid + provider id) a project follows, plus its options.
 * Smart commits and cross-project key matching are OFF by default and must be switched on per link.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_repositories')) {
            return;
        }

        Schema::create('project_repositories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->uuid('git_connection_uuid');
            $table->string('provider', 20)->default('github');
            $table->unsignedBigInteger('provider_repo_id');
            $table->string('full_name', 255);
            $table->string('default_branch', 150)->nullable();
            $table->string('html_url', 500)->nullable();
            $table->boolean('sync_enabled')->default(true);
            $table->boolean('smart_commits_enabled')->default(false);
            $table->boolean('allow_cross_project')->default(false);
            $table->unsignedBigInteger('linked_by')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'provider', 'provider_repo_id']);
            // Webhook routing: "which projects follow this repository?"
            $table->index(['git_connection_uuid', 'provider_repo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_repositories');
    }
};
