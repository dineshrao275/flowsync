<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRMS/P20.1 — the bridge between people and work.
 *
 * `hrms_task_links` names the relationship between an employee record and a
 * project task: a goal measured by linked work, a checklist item converted
 * to a real task, a leave or expense case with a task tracking it. The link
 * is evidence-only (D2.10) — it lets a reviewer audit a number, never feed
 * a score — which is why the grain is (employee, task, kind): the same task
 * can evidence a goal and track an onboarding run without the two claims
 * colliding.
 *
 * `tasks.hrms_employee_id` is the reverse affordance: an HR-owned task
 * ("submit your bank details") filed against a person, so it surfaces on
 * their HR home. Nullable, null when the person is deleted out from under
 * the task: the work survives as unassigned rather than vanishing with
 * the record.
 *
 * **`tasks.hrms_employee_id` is added with raw SQL, not `constrained()`.**
 * `Schema::table()` + `constrained()` rebuilds through a `__temp__` copy
 * on SQLite while PostgreSQL takes a native alter (the P5.1 lesson) — a
 * raw `ADD COLUMN ... REFERENCES` is a native alter on both grammars and
 * touches nothing else; the index is created separately. `down()` drops
 * the index *before* the column: SQLite refuses `DROP COLUMN` on an
 * indexed column. Quoting comes from the connection's own grammar, never
 * backticks — PostgreSQL rejects those with 42601.
 *
 * **No `tenant_id` column** — the tenant database is the isolation boundary
 * (Phase 13). Repair-safe: the table is created only when absent, the
 * column added only when missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hrms_task_links')) {
            Schema::create('hrms_task_links', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
                $table->enum('kind', ['goal', 'onboarding', 'attendance', 'expense', 'payroll', 'leave', 'review']);
                $table->string('note')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['employee_id', 'task_id', 'kind']);
                $table->index(['task_id', 'kind']);
            });
        }

        $this->addHrmsEmployeeIdToTasks();
    }

    public function down(): void
    {
        Schema::dropIfExists('hrms_task_links');

        $this->dropHrmsEmployeeIdFromTasks();
    }

    private function addHrmsEmployeeIdToTasks(): void
    {
        if (Schema::hasColumn('tasks', 'hrms_employee_id')) {
            return;
        }

        $grammar = Schema::getConnection()->getQueryGrammar();
        $table = $grammar->wrapTable('tasks');
        $column = $grammar->wrap('hrms_employee_id');
        $references = $grammar->wrapTable('employees');

        DB::statement("alter table {$table} add column {$column} integer null references {$references} (id) on delete set null");
        Schema::table('tasks', function (Blueprint $table) {
            $table->index('hrms_employee_id');
        });
    }

    private function dropHrmsEmployeeIdFromTasks(): void
    {
        if (! Schema::hasColumn('tasks', 'hrms_employee_id')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['hrms_employee_id']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('hrms_employee_id');
        });
    }
};
