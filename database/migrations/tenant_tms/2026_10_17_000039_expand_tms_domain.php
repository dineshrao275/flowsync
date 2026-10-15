<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Expand workspaces with metadata and settings
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('color', 16)->nullable()->after('icon');
            $table->foreignId('default_assignee_id')->nullable()->after('timezone')->constrained('users')->nullOnDelete();
            $table->json('settings')->nullable()->after('default_assignee_id');
        });

        // 2. Expand projects with metadata
        Schema::table('projects', function (Blueprint $table) {
            $table->string('color', 16)->nullable()->after('icon');
            $table->foreignId('default_assignee_id')->nullable()->after('color')->constrained('users')->nullOnDelete();
        });

        // 3. Issue Types catalog
        Schema::create('issue_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('icon', 64)->nullable();
            $table->string('color', 16)->nullable();
            $table->boolean('is_subtask')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        // 4. Project Components
        Schema::create('project_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('lead_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'name']);
        });

        // 5. Project Versions / Releases
        Schema::create('project_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->date('release_date')->nullable();
            $table->boolean('released')->default(false);
            $table->boolean('archived')->default(false);
            $table->timestamps();

            $table->unique(['project_id', 'name']);
        });

        // 6. Expand tasks with Jira-style attributes
        Schema::table('tasks', function (Blueprint $table) {
            $table->date('start_date')->nullable()->after('due_date');
            $table->decimal('story_points', 5, 1)->nullable()->after('start_date');
            $table->foreignId('issue_type_id')->nullable()->after('story_points')->constrained('issue_types')->nullOnDelete();
            $table->foreignId('version_id')->nullable()->after('issue_type_id')->constrained('project_versions')->nullOnDelete();
        });

        // 7. Task to Component pivot
        Schema::create('task_component', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('component_id')->constrained('project_components')->cascadeOnDelete();

            $table->primary(['task_id', 'component_id']);
        });

        // 8. Task Watchers
        Schema::create('task_watchers', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['task_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_watchers');
        Schema::dropIfExists('task_component');

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['version_id']);
            $table->dropForeign(['issue_type_id']);
            $table->dropColumn(['start_date', 'story_points', 'issue_type_id', 'version_id']);
        });

        Schema::dropIfExists('project_versions');
        Schema::dropIfExists('project_components');
        Schema::dropIfExists('issue_types');

        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['default_assignee_id']);
            $table->dropColumn(['color', 'default_assignee_id']);
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropForeign(['default_assignee_id']);
            $table->dropColumn(['color', 'default_assignee_id', 'settings']);
        });
    }
};
