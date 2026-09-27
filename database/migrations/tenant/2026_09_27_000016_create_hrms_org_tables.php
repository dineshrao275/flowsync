<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase 15 — HRMS organization structure (tenant database).
 *
 *   departments  — the reporting tree: a self-referencing `parent_id` plus a
 *       `head_employee_id` so "who runs this team" is answerable without a
 *       separate manager-of-department record.
 *   designations — what somebody does. Normalizes the free-text
 *       `employees.designation` written in P2, which is why the backfill at
 *       the bottom of this migration exists.
 *   locations    — a work site, optionally geofenced for the attendance module
 *       in P5. Address fields are a *site* address, not a person's home
 *       address, so they are not covered by the P2.4 masking rules.
 *
 * `employees` gains three nullable FKs, completing the set P2.1 deliberately
 * deferred: `department_id` and `designation_id` (where the free text lived)
 * and `location_id`. `shift_id` still arrives in P5 with the shifts table.
 *
 * **FK actions: `nullOnDelete` everywhere, and no cascades.** The tempting
 * cascade here is `departments.parent_id`, and it is the wrong call: cascading
 * would destroy an entire subtree when one mid-level department is removed.
 * Orphaning a department is recoverable (re-parent it, or deactivate it);
 * silently deleting a hundred people from the org chart is not. This mirrors
 * `employees.manager_id` for the same reason. The real protection is
 * service-level: P3.2 refuses a hard delete of a department that has children
 * or employees and suggests reassignment or deactivation instead.
 *
 * **`geo_lat`/`geo_lng` are `decimal(10,7)`, never float.** Binary floating
 * point cannot represent most decimal fractions exactly, so a coordinate
 * round-trips to a different value than the one stored. A geofence comparing
 * a float latitude against a stored one then has to carry an epsilon fudge
 * factor, and the comparison is wrong by a different amount at every latitude.
 * Seven decimal places is ~1 cm — far finer than any geofence, and exact.
 *
 * **No `tenant_id` column** — the tenant database is the isolation boundary
 * (Phase 13).
 *
 * Repair-safe, and every step independent: `tenants:provision` re-runs tenant
 * migrations to repair a database that failed partway, and a partial run must
 * be completable. Each table is created only when absent, each `ALTER` is
 * applied only when its own column is missing (so a run interrupted between
 * two ALTERs resumes at the right one), and the backfill is `whereNull`-guarded
 * so re-running it changes nothing. The same "guard each step on its own"
 * discipline as `TenantProvisioner::provisionHrmsDefaults()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createDepartments();
        $this->createDesignations();
        $this->createLocations();
        $this->addEmployeeOrgColumns();
        $this->backfillDesignationsFromFreeText();
    }

    public function down(): void
    {
        // The employee columns go first: they reference the org tables, and a
        // FK cannot be dropped while a table on the other side of it remains.
        $this->dropEmployeeColumns();

        Schema::dropIfExists('locations');
        Schema::dropIfExists('designations');
        Schema::dropIfExists('departments');
    }

    private function createDepartments(): void
    {
        if (Schema::hasTable('departments')) {
            return;
        }

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // Unique per tenant, which is the database's job here: two
            // departments that differ only in case or spacing are one
            // department as far as anybody searching is concerned.
            $table->string('slug')->unique();

            // Optional short label for org charts and payroll exports ("ENG"),
            // so it must not be required. Not unique — a tenant is free to
            // leave it blank for most rows and only code the ones it reports on.
            $table->string('code', 32)->nullable();

            // Self-reference for the tree. `nullOnDelete`, not cascade: see the
            // class docblock.
            $table->foreignId('parent_id')->nullable()->constrained('departments')->nullOnDelete();

            // The department head is a *person*, and people leave. A deleted
            // employee must leave the department standing with no head, not
            // delete the department.
            $table->foreignId('head_employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->text('description')->nullable();

            // Deactivation, not deletion, is the retirement path — see the
            // class docblock.
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->index('position');
            $table->index('is_active');
            // Every ancestor walk ("this department and everything under it")
            // and the breadcrumb on the detail pane.
            $table->index('parent_id');
            $table->index('head_employee_id');
        });
    }

    private function createDesignations(): void
    {
        if (Schema::hasTable('designations')) {
            return;
        }

        Schema::create('designations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('code', 32)->nullable();

            // A **numeric** seniority band, not a label. "Senior" is already in
            // the name ("Senior Engineer"), whereas the band is what a comp
            // matrix, a promotion review and a headcount-by-level chart all
            // need to sort and group on. Nullable because a tenant with a flat
            // structure never sets it, and the bands themselves are per-tenant
            // — this is deliberately not an enum, since a global list of
            // "junior/mid/senior/lead" is wrong for every second company.
            $table->unsignedSmallInteger('level')->nullable();

            // Optional: most companies have bands that span departments
            // ("Manager" is a level, not a department), and a designation that
            // belonged to one department would be invisible to everybody else.
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->index('position');
            $table->index('is_active');
            // "Designations in this department", and the level breakdown.
            $table->index('department_id');
            $table->index('level');
        });
    }

    private function createLocations(): void
    {
        if (Schema::hasTable('locations')) {
            return;
        }

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();

            // A work site, not somebody's home: this is the address a visitor
            // is sent to and the one printed on a payslip's tax declaration.
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code', 16)->nullable();

            // ISO 3166-1 alpha-2, matching `employees.country`.
            $table->string('country', 2)->nullable();

            // An IANA name ("Asia/Kolkata"), not a UTC offset: payroll and
            // attendance both need to know the site's *rules* — DST, a
            // half-hour offset, a fiscal year that starts on a local midnight.
            $table->string('timezone', 64)->nullable();

            // See the class docblock on why these are decimal, not float.
            $table->decimal('geo_lat', 10, 7)->nullable();
            $table->decimal('geo_lng', 10, 7)->nullable();

            // How far from the site point counts as "at the office", for the
            // attendance geofence in P5. Null radius with no fence means the
            // location simply has no geofence, rather than a radius of 0
            // matching exactly one spot on the planet.
            $table->unsignedInteger('geo_radius_m')->nullable();
            $table->boolean('is_geo_fenced')->default(false);

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->index('position');
            $table->index('is_active');
        });
    }

    /**
     * `department_id`, `designation_id` and `location_id` on `employees`.
     *
     * **Raw `ADD COLUMN ... REFERENCES`, deliberately, and not
     * `foreignId()->constrained()`.** Both grammars accept this exact form and
     * neither needs to touch any other column, which matters because
     * `constrained()` inside `Schema::table()` is *destructive on SQLite*:
     * a `foreign` command is in `SQLiteGrammar::getAlterCommands()`, so Laravel
     * rebuilds the table through a `__temp__` copy. The rebuild regenerates
     * every column from doctrine introspection, and **doctrine does not report
     * column-level CHECK constraints** — so `employees.status` and
     * `employees.work_mode` came out of the ALTER as bare `varchar` and lost
     * their `check ("status" in (...))`. `HrmsEmployeeTablesTest` caught it.
     *
     * PostgreSQL takes a native `ALTER TABLE ... ADD COLUMN ... REFERENCES`
     * and never rebuilds, so the loss was invisible there — the worst kind of
     * divergence: a destructive rewrite that only happens on the test fast-path
     * and on local sqlite files, leaving them with a weaker schema than
     * production. The tests would have stopped guarding an enum while every
     * real deployment still enforced it.
     *
     * The columns are added one at a time and each is skipped if present, so a
     * run interrupted between two of them resumes at the right one. The index
     * goes through the schema builder afterwards: creating an index is not a
     * rebuild, so it is safe.
     */
    private function addEmployeeOrgColumns(): void
    {
        foreach ([
            'department_id' => 'departments',
            'designation_id' => 'designations',
            'location_id' => 'locations',
        ] as $column => $table) {
            if (Schema::hasColumn('employees', $column)) {
                continue;
            }

            // `null` default, which SQLite additionally requires for a column
            // carrying a REFERENCES clause.
            DB::statement(sprintf(
                'alter table %s add column %s integer null references %s (%s) on delete set null',
                $this->wrapTable('employees'),
                $this->wrap($column),
                $this->wrap($table),
                $this->wrap('id'),
            ));

            $this->addIndex('employees', $column);
        }
    }

    private function addIndex(string $table, string $column): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($column) {
            $blueprint->index($column);
        });
    }

    /**
     * Drop the indexes before the columns: SQLite refuses `DROP COLUMN` on an
     * indexed column, so the order is the whole implementation.
     */
    private function dropEmployeeColumns(): void
    {
        foreach (['department_id', 'designation_id', 'location_id'] as $column) {
            if (! Schema::hasColumn('employees', $column)) {
                continue;
            }

            $index = "employees_{$column}_index";

            if (Schema::hasIndex('employees', $index)) {
                Schema::table('employees', function (Blueprint $blueprint) use ($index) {
                    $blueprint->dropIndex($index);
                });
            }

            DB::statement(sprintf(
                'alter table %s drop column %s',
                $this->wrapTable('employees'),
                $this->wrap($column),
            ));
        }
    }

    /**
     * Identifier quoting for the raw statements above, via the connection's own
     * grammar.
     *
     * **Backticks are not a portable choice**, and assuming they were cost a
     * round trip: SQLite and MySQL accept `` `col` ``, PostgreSQL parses it as
     * a syntax error (`42601`), and the failure only appeared on the PostgreSQL
     * path — where the whole point of the raw statement is to preserve the
     * PostgreSQL schema's CHECK constraints.
     *
     * Every identifier here is a hardcoded literal from this migration, never
     * user input, so this is quoting and not escaping — but it still has to come
     * from the grammar rather than a hand-typed character.
     */
    private function wrap(string $identifier): string
    {
        return Schema::getConnection()->getQueryGrammar()->wrap($identifier);
    }

    private function wrapTable(string $table): string
    {
        return Schema::getConnection()->getQueryGrammar()->wrapTable($table);
    }

    /**
     * Normalize the P2 free-text `employees.designation` into the catalog.
     *
     * A tenant that has been running the P2 build has people whose job is a
     * string ("Senior Engineer"). Where a designation with a matching slug
     * exists, the record is linked to it — and, through the designation, to
     * its department, which is a second free answer rather than a guess: the
     * department is the one the *matched row* declares, not one inferred from
     * the words.
     *
     * **`department_id` is only filled when the employee has none.** A
     * hand-assigned department is somebody's deliberate decision; the slug
     * match is a heuristic over text, and a heuristic must never overwrite a
     * fact.
     *
     * `whereNull('designation_id')` is the re-runnable guard. Trashed employees
     * are skipped: the normalized value would be right, but rewriting a deleted
     * record's columns is noise, and the free text is still there for whoever
     * restores it.
     *
     * `updated_at` is deliberately **not** touched. Nothing about these people
     * changed — the same fact is simply stored in a better column — and
     * stamping the migration time would make every backfilled employee look
     * "just edited" in a directory ordered by recent change, inventing a
     * recency that never happened.
     */
    private function backfillDesignationsFromFreeText(): void
    {
        if (! Schema::hasColumn('employees', 'designation_id')) {
            return;
        }

        $designations = DB::table('designations')
            ->select('id', 'slug', 'department_id')
            ->get()
            ->keyBy('slug');

        // Nothing to match against: a tenant whose catalog is still empty gets
        // no links rather than a scan of the whole employees table.
        if ($designations->isEmpty()) {
            return;
        }

        DB::table('employees')
            ->whereNotNull('designation')
            ->whereNull('designation_id')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(200, function ($employees) use ($designations) {
                foreach ($employees as $employee) {
                    $match = $designations->get(Str::slug($employee->designation));

                    if ($match === null) {
                        continue;
                    }

                    DB::table('employees')
                        ->where('id', $employee->id)
                        ->update([
                            'designation_id' => $match->id,
                            'department_id' => $employee->department_id ?? $match->department_id,
                        ]);
                }
            });
    }
};
