<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F4.8 — board types (scrum | kanban | basic) and a per-project issue-type scheme. Existing projects keep
 * behaving as they do today: `kanban` and an empty scheme (= every issue type allowed).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('projects', 'board_type')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->string('board_type', 16)->default('kanban');
            });
        }

        if (! Schema::hasTable('project_issue_types')) {
            Schema::create('project_issue_types', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
                $table->foreignId('issue_type_id')->constrained('issue_types')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['project_id', 'issue_type_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_issue_types');
        if (Schema::hasColumn('projects', 'board_type')) {
            Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('board_type'));
        }
    }
};
