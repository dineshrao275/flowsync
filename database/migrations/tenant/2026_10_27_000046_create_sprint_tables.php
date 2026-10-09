<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Sprints, backlog membership and the scope-change log burndown is computed from (P4.2). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sprints')) {
            Schema::create('sprints', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
                $table->string('name');
                $table->text('goal')->nullable();
                $table->string('status', 12)->default('planned'); // planned | active | completed
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->decimal('committed_points', 8, 2)->nullable();
                $table->decimal('completed_points', 8, 2)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['project_id', 'status']);
            });
        }

        if (! Schema::hasTable('sprint_task_events')) {
            Schema::create('sprint_task_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sprint_id')->constrained('sprints')->cascadeOnDelete();
                $table->unsignedBigInteger('task_id');
                $table->string('type', 8); // added | removed
                $table->decimal('points', 8, 2)->nullable();
                $table->timestamp('at')->useCurrent();

                $table->index(['sprint_id', 'at']);
                $table->index('task_id');
            });
        }

        // Raw ALTER: Schema::table()+constrained() rebuilds the table on SQLite and drops other
        // columns' CHECK constraints (see AGENTS.md pitfalls).
        if (! Schema::hasColumn('tasks', 'sprint_id')) {
            $grammar = Schema::getConnection()->getQueryGrammar();
            DB::statement('alter table '.$grammar->wrap('tasks').' add column '.$grammar->wrap('sprint_id').' integer null references '.$grammar->wrap('sprints').' ('.$grammar->wrap('id').') on delete set null');
            Schema::table('tasks', fn (Blueprint $t) => $t->index('sprint_id'));
        }
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $t) => $t->dropIndex(['sprint_id']));
        Schema::table('tasks', fn (Blueprint $t) => $t->dropColumn('sprint_id'));
        Schema::dropIfExists('sprint_task_events');
        Schema::dropIfExists('sprints');
    }
};
