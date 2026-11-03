<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Queryable Git state per task (rows outlive an unlinked repository - project_repository_id is nulled, not cascaded -
 * so history survives unless the manager asks for it to be removed): pull requests, commits and branches that mention a task key, the GitHub login
 * -> user map used by smart commits, and the per-delivery ledger that makes a replay a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('task_pull_requests')) {
            Schema::create('task_pull_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
                $table->foreignId('project_repository_id')->nullable()->constrained('project_repositories')->nullOnDelete();
                $table->string('provider', 20)->default('github');
                $table->unsignedBigInteger('provider_pr_id')->nullable();
                $table->unsignedInteger('number');
                $table->string('title', 255);
                $table->string('state', 10)->default('open');
                $table->boolean('draft')->default(false);
                $table->string('author_login', 120)->nullable();
                $table->string('author_avatar_url', 500)->nullable();
                $table->string('html_url', 500)->nullable();
                $table->string('head_branch', 255)->nullable();
                $table->string('base_branch', 255)->nullable();
                $table->timestamp('merged_at')->nullable();
                $table->string('checks_state', 10)->default('none');
                $table->timestamp('last_event_at')->nullable();
                $table->timestamps();

                $table->unique(['task_id', 'project_repository_id', 'number']);
                $table->index(['project_repository_id', 'number']);
            });
        }

        if (! Schema::hasTable('task_commits')) {
            Schema::create('task_commits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
                $table->foreignId('project_repository_id')->nullable()->constrained('project_repositories')->nullOnDelete();
                $table->string('sha', 64);
                $table->string('message', 500);
                $table->string('author_login', 120)->nullable();
                $table->string('author_name', 150)->nullable();
                $table->string('html_url', 500)->nullable();
                $table->timestamp('committed_at')->nullable();
                $table->timestamps();

                $table->unique(['task_id', 'project_repository_id', 'sha']);
                $table->index('sha');
            });
        }

        if (! Schema::hasTable('task_branches')) {
            Schema::create('task_branches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
                $table->foreignId('project_repository_id')->nullable()->constrained('project_repositories')->nullOnDelete();
                $table->string('name', 255);
                $table->string('provider_ref', 300)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->timestamps();

                $table->unique(['project_repository_id', 'name']);
                $table->index('task_id');
            });
        }

        if (! Schema::hasTable('git_identities')) {
            Schema::create('git_identities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('provider', 20)->default('github');
                $table->string('provider_user_id', 40)->nullable();
                $table->string('login', 120);
                $table->string('avatar_url', 500)->nullable();
                $table->timestamp('linked_at')->nullable();
                $table->timestamps();

                $table->unique(['provider', 'login']);
                $table->unique(['user_id', 'provider']);
            });
        }

        if (! Schema::hasTable('git_event_links')) {
            Schema::create('git_event_links', function (Blueprint $table) {
                $table->id();
                $table->string('delivery_uuid', 64);
                $table->foreignId('project_repository_id')->constrained('project_repositories')->cascadeOnDelete();
                $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
                $table->string('kind', 20);
                $table->string('provider_object_id', 100);
                $table->timestamps();

                $table->unique(['delivery_uuid', 'task_id', 'kind', 'provider_object_id']);
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        foreach (['git_event_links', 'git_identities', 'task_branches', 'task_commits', 'task_pull_requests'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
