<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Status-changing smart commands (#close, #transition) are a separate opt-in from smart commits as a whole: a
 * team may want commit comments and time logs without letting a commit message move work to Done.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_repositories') && ! Schema::hasColumn('project_repositories', 'smart_commit_transitions_enabled')) {
            Schema::table('project_repositories', function (Blueprint $table) {
                $table->boolean('smart_commit_transitions_enabled')->default(false)->after('smart_commits_enabled');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('project_repositories', 'smart_commit_transitions_enabled')) {
            Schema::table('project_repositories', function (Blueprint $table) {
                $table->dropColumn('smart_commit_transitions_enabled');
            });
        }
    }
};
