<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P4.1 issue hierarchy: a level on every issue type (0 initiative, 1 epic, 2 standard,
 * 3 sub-task) and `tasks.epic_id`, the epic link. The link is its own column rather than
 * `parent_id` so the board (top-level = no parent) keeps showing the stories of an epic.
 *
 * `epic_id` is added with raw SQL: `constrained()` inside Schema::table() rebuilds the table on
 * SQLite and drops the other columns' CHECK constraints (see AGENTS.md pitfalls).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('issue_types', 'hierarchy_level')) {
            Schema::table('issue_types', function (Blueprint $table) {
                $table->unsignedSmallInteger('hierarchy_level')->default(2)->after('is_subtask');
            });
        }

        DB::table('issue_types')->where('slug', 'epic')->update(['hierarchy_level' => 1]);
        DB::table('issue_types')->where('is_subtask', true)->update(['hierarchy_level' => 3]);

        if (! DB::table('issue_types')->where('slug', 'initiative')->exists()) {
            DB::table('issue_types')->insert([
                'name' => 'Initiative', 'slug' => 'initiative', 'description' => 'A strategic goal above epics.',
                'icon' => 'flag', 'color' => '#f59e0b', 'is_subtask' => false, 'hierarchy_level' => 0,
                'position' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        if (! Schema::hasColumn('tasks', 'epic_id')) {
            $grammar = Schema::getConnection()->getQueryGrammar();
            $tasks = $grammar->wrapTable('tasks');
            $col = $grammar->wrap('epic_id');
            DB::statement("alter table {$tasks} add column {$col} bigint null references {$tasks} ({$grammar->wrap('id')}) on delete set null");
            Schema::table('tasks', fn (Blueprint $table) => $table->index('epic_id', 'tasks_epic_id_index'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tasks', 'epic_id')) {
            Schema::table('tasks', fn (Blueprint $table) => $table->dropIndex('tasks_epic_id_index'));
            Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn('epic_id'));
        }
        if (Schema::hasColumn('issue_types', 'hierarchy_level')) {
            Schema::table('issue_types', fn (Blueprint $table) => $table->dropColumn('hierarchy_level'));
        }
    }
};
