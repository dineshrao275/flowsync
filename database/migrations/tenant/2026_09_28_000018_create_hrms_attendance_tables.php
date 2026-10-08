<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15 — HRMS attendance tables (tenant database).
 *
 * Six tables plus one altered column: shifts are the catalogue, rosters pin
 * a person to a shift over a date range, punches are the raw clock events,
 * days are the derived photograph (one row per person per date), ip_rules
 * gate where a punch may come from, and regularization_requests are the
 * “my punch is wrong” flow that the shared approvals engine decides.
 *
 * **`employees.shift_id` is added with raw SQL, not `constrained()`.**
 * `Schema::table()` + `constrained()` on SQLite rebuilds the whole table
 * through a `__temp__` copy, and doctrine introspection does not report
 * column-level CHECKs — so P3.1 silently stripped `employees.status` and
 * `employees.work_mode` of their `check (...) in` guards on the test
 * fast-path while PostgreSQL kept them. A raw `ADD COLUMN ... REFERENCES`
 * is a native alter on both grammars and touches nothing else; the index is
 * created separately, because index creation is not a rebuild. `down()`
 * drops the index *before* the column: SQLite refuses `DROP COLUMN` on an
 * indexed column. Quoting comes from the connection’s own grammar, never
 * backticks — PostgreSQL rejects those with 42601.
 *
 * The shift is a convenience default only; the roster is authoritative when
 * the day is computed. A null shift means “no default”, not “no shift”.
 *
 * **No `tenant_id` column** — the tenant database is the isolation boundary
 * (Phase 13). Repair-safe: each table is created only when absent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createAttendanceShifts();
        $this->createAttendanceRosters();
        $this->createAttendancePunches();
        $this->createAttendanceDays();
        $this->createAttendanceIpRules();
        $this->createAttendanceRegularizationRequests();
        $this->addShiftIdToEmployees();
    }

    public function down(): void
    {
        $this->dropShiftIdFromEmployees();
        Schema::dropIfExists('attendance_regularization_requests');
        Schema::dropIfExists('attendance_ip_rules');
        Schema::dropIfExists('attendance_days');
        Schema::dropIfExists('attendance_punches');
        Schema::dropIfExists('attendance_rosters');
        Schema::dropIfExists('attendance_shifts');
    }

    private function createAttendanceShifts(): void
    {
        if (Schema::hasTable('attendance_shifts')) {
            return;
        }

        Schema::create('attendance_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            // Time-of-day, not timestamps: a shift is “09:00–18:00 every
            // weekday”, and storing a full datetime would pretend a date is
            // part of the definition. Overnight shifts read start > end with
            // `is_night` set — the computation, not the schema, wraps those.
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedInteger('break_minutes')->default(0);
            $table->unsignedInteger('grace_minutes')->default(0);
            $table->decimal('min_hours', 5, 2)->nullable();
            $table->boolean('is_night')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index('is_active');
            $table->index('position');
        });
    }

    private function createAttendanceRosters(): void
    {
        if (Schema::hasTable('attendance_rosters')) {
            return;
        }

        Schema::create('attendance_rosters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            // Null when the shift was deleted out from under the roster: the
            // assignment survives as “this person, these dates, no shift”,
            // and the day computation falls back to the employee default.
            $table->foreignId('shift_id')->nullable()->constrained('attendance_shifts')->nullOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            // Seven ints, Monday-first, 1 = off: `[0,0,0,0,0,1,1]` is the
            // ordinary weekend. JSON rather than seven booleans because the
            // roster editor moves whole patterns around, not single days.
            $table->json('weekly_offs')->nullable();
            $table->boolean('is_flexible')->default(false);
            $table->timestamps();

            // One row per person per start date: overlapping ranges for the
            // same employee are a data entry error the schema refuses.
            $table->unique(['employee_id', 'effective_from']);
            $table->index('shift_id');
        });
    }

    private function createAttendancePunches(): void
    {
        if (Schema::hasTable('attendance_punches')) {
            return;
        }

        Schema::create('attendance_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->timestamp('punch_at');
            $table->enum('direction', ['in', 'out']);
            // `regularized` marks punches the approval flow rewrote; `auto`
            // marks rows the rollup or an import created without a human
            // hand on a clock. Both matter when someone asks “who said
            // they were here”.
            $table->enum('source', ['web', 'mobile', 'kiosk', 'import', 'auto', 'regularized'])->default('web');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('ip', 64)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('device_id')->nullable();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            // Out-of-range is a flag, never a block: a mispinned geofence
            // must not lock a person out of recording that they worked, and
            // the reason column is what the reviewer reads instead.
            $table->boolean('is_out_of_range')->default(false);
            $table->string('out_of_range_reason')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The pairing query (“today’s punches for this person, in order”)
            // and the rollup scan (“everything punched on this date”).
            $table->index(['employee_id', 'punch_at']);
            $table->index('punch_at');
        });
    }

    private function createAttendanceDays(): void
    {
        if (Schema::hasTable('attendance_days')) {
            return;
        }

        Schema::create('attendance_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->foreignId('shift_id')->nullable()->constrained('attendance_shifts')->nullOnDelete();
            $table->foreignId('roster_id')->nullable()->constrained('attendance_rosters')->nullOnDelete();
            $table->timestamp('first_in_at')->nullable();
            $table->timestamp('last_out_at')->nullable();
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('break_minutes')->default(0);
            $table->unsignedInteger('late_by_minutes')->default(0);
            $table->unsignedInteger('early_by_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->enum('status', ['present', 'absent', 'half_day', 'late', 'leave', 'holiday', 'week_off', 'remote', 'inactive'])->default('absent');
            $table->boolean('is_regularized')->default(false);
            $table->foreignId('regularized_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            // One photograph per person per date: the rollup’s find-or-create
            // and every recompute target this pair, and two rows for one day
            // would let “present” and “absent” both be true.
            $table->unique(['employee_id', 'work_date']);
            $table->index('work_date');
            $table->index('status');
        });
    }

    private function createAttendanceIpRules(): void
    {
        if (Schema::hasTable('attendance_ip_rules')) {
            return;
        }

        Schema::create('attendance_ip_rules', function (Blueprint $table) {
            $table->id();
            $table->string('cidr')->unique();
            $table->string('label')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    private function createAttendanceRegularizationRequests(): void
    {
        if (Schema::hasTable('attendance_regularization_requests')) {
            return;
        }

        Schema::create('attendance_regularization_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_day_id')->constrained('attendance_days')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->timestamp('requested_punch_at')->nullable();
            $table->timestamp('requested_first_in_at')->nullable();
            $table->text('reason');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            // The shared approvals engine decides these (P1): the row points
            // at its approval, and deleting the approval nulls the link
            // rather than destroying the request that asked for it.
            $table->foreignId('approval_id')->nullable()->constrained('approvals')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision_note')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index('attendance_day_id');
        });
    }

    /**
     * See the class docblock: raw SQL, grammar-quoted, index separately.
     */
    private function addShiftIdToEmployees(): void
    {
        if (Schema::hasColumn('employees', 'shift_id')) {
            return;
        }

        $grammar = Schema::getConnection()->getQueryGrammar();
        $table = $grammar->wrapTable('employees');
        $column = $grammar->wrap('shift_id');
        $references = $grammar->wrapTable('attendance_shifts');

        DB::statement("alter table {$table} add column {$column} integer null references {$references} (id) on delete set null");
        Schema::table('employees', function (Blueprint $table) {
            $table->index('shift_id');
        });
    }

    /**
     * Index first, then the column: SQLite refuses DROP COLUMN on an
     * indexed column, so the reverse order fails on the test fast-path
     * while passing everywhere else.
     */
    private function dropShiftIdFromEmployees(): void
    {
        if (! Schema::hasColumn('employees', 'shift_id')) {
            return;
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['shift_id']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('shift_id');
        });
    }
};
