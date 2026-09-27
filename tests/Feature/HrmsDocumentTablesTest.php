<?php

namespace Tests\Feature;

use App\Models\Hrms\Employee\Employee;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P13.1 — the HRMS document tables.
 *
 * The isolation trait migrates every tenant, so this asserts the migration's
 * own contract rather than that the tables exist: the FK actions, the enum
 * CHECKs, the soft deletes, and — most importantly — that a second run is a
 * no-op, since `tenants:provision` re-runs tenant migrations to repair a
 * database that failed partway.
 *
 * **The enum CHECK tests are the deliberate pair with `HrmsEmployeeTablesTest`.**
 * P3.1 added three columns to `employees` with `constrained()` instead of a raw
 * `ADD COLUMN ... REFERENCES`, and SQLite silently rebuilt the table without
 * its column-level CHECKs — so the test fast-path and any local sqlite file
 * ended up with a *weaker schema than production*, with the enum guards still
 * passing against PostgreSQL. A table whose CHECKs silently vanish is
 * invisible on the one path production uses, which is the worst kind of
 * divergence. These tests insert a bad value and expect the database itself to
 * refuse, on whichever grammar the suite is running.
 */
class HrmsDocumentTablesTest extends TestCase
{
    use IsolatesDatabase;

    private function migration(): object
    {
        return require database_path('migrations/tenant/2026_10_02_000026_create_hrms_document_tables.php');
    }

    public function test_the_document_tables_exist_on_a_fresh_tenant(): void
    {
        foreach (['document_types', 'employee_documents'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }

    public function test_there_is_no_tenant_id_column(): void
    {
        foreach (['document_types', 'employee_documents'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'tenant_id'), "{$table}.tenant_id must not exist");
        }
    }

    public function test_a_document_type_slug_is_unique(): void
    {
        $id = $this->insertType(['name' => 'Passport', 'slug' => 'passport']);

        $this->expectException(QueryException::class);

        $this->insertType(['name' => 'Passport (EU)', 'slug' => 'passport', 'id' => $id + 1000]);
    }

    public function test_deleting_an_employee_deletes_their_documents(): void
    {
        $employee = $this->insertEmployee();
        $document = $this->insertDocument(['employee_id' => $employee]);

        // A hard delete, not a soft one: `employees` uses SoftDeletes, and a
        // soft delete leaves the row (and therefore the document) in place. It
        // is the *hard* path that has to clean up, or a purged employee strands
        // files nobody can reach.
        DB::table('employees')->where('id', $employee)->delete();

        $this->assertNull(
            DB::table('employee_documents')->where('id', $document)->value('id'),
            'a document with no employee is unreachable, and its file is still on disk',
        );
    }

    public function test_deleting_a_document_type_orphans_its_documents_rather_than_deleting_them(): void
    {
        // The opposite action, on purpose. A catalogue row is a category; the
        // file is a person's passport. Cascading here would delete a statutory
        // document because somebody retired a type from the picker — and the
        // file on disk would survive, which is the worst of both: data lost
        // from the database, bytes still being retained past their retention.
        $type = $this->insertType(['name' => 'Work Visa', 'slug' => 'work_visa']);
        $document = $this->insertDocument(['document_type_id' => $type]);

        DB::table('document_types')->where('id', $type)->delete();

        $row = DB::table('employee_documents')->where('id', $document)->first();

        $this->assertNotNull($row, 'a document must not be destroyed by its own catalogue row');
        $this->assertNull($row->document_type_id, 'it is left untyped rather than gone');
    }

    public function test_a_document_row_is_soft_deleted(): void
    {
        $document = $this->insertDocument();

        DB::table('employee_documents')->where('id', $document)->update(['deleted_at' => now()]);

        $this->assertNotNull(
            DB::table('employee_documents')->where('id', $document)->first(),
            'a soft delete hides the row; it does not remove it, so a trashed document is restorable',
        );
    }

    /**
     * The database refuses a value outside the enum, on the grammar the suite
     * happens to be running.
     *
     * Without this, an enum is a comment. The application writes the PHP enum
     * and the database enforces nothing, so a hand-run UPDATE, a seed script or
     * a future refactor can write a status no reader expects and the failure
     * surfaces as a blank row on the compliance panel instead of at the write.
     */
    public function test_the_status_enum_is_enforced_by_the_database(): void
    {
        $this->expectException(QueryException::class);

        $this->insertDocument(['status' => 'archived']);
    }

    public function test_the_visibility_enum_is_enforced_by_the_database(): void
    {
        $this->expectException(QueryException::class);

        $this->insertDocument(['visibility' => 'world']);
    }

    public function test_the_source_enum_is_enforced_by_the_database(): void
    {
        $this->expectException(QueryException::class);

        $this->insertDocument(['source' => 'telepathy']);
    }

    public function test_the_document_type_category_enum_is_enforced_by_the_database(): void
    {
        $this->expectException(QueryException::class);

        $this->insertType(['category' => 'astrology']);
    }

    /**
     * The compliance query is "what expires soon, and what is still live", so
     * the pair is indexed — an index on `expires_at` alone narrows to the whole
     * historical tail before the status filter is ever applied.
     */
    public function test_the_expiry_indexes_exist(): void
    {
        $this->assertTrue(Schema::hasIndex('employee_documents', ['expires_at']));
        $this->assertTrue(Schema::hasIndex('employee_documents', ['status', 'expires_at']));
        $this->assertTrue(Schema::hasIndex('employee_documents', ['employee_id', 'status']));
    }

    /**
     * `tenants:provision` re-runs pending migrations to repair a database that
     * failed partway, so a second `up()` has to be a no-op rather than a
     * duplicate-table error that blocks the repair.
     */
    public function test_the_migration_is_safe_to_run_twice(): void
    {
        $type = $this->insertType(['name' => 'Passport', 'slug' => 'passport']);
        $document = $this->insertDocument(['document_type_id' => $type]);

        $this->migration()->up();

        $this->assertSame(1, DB::table('document_types')->where('slug', 'passport')->count());
        $this->assertSame(1, DB::table('employee_documents')->where('id', $document)->count());
    }

    public function test_a_document_defaults_to_the_local_disk_and_a_pending_status(): void
    {
        $document = $this->insertDocument();
        $row = DB::table('employee_documents')->where('id', $document)->first();

        // The storage root is private, so "local" means a path only the signed
        // route can reach — never a publicly addressable URL.
        $this->assertSame('local', $row->file_disk);
        $this->assertSame('pending', $row->status, 'a document is not evidence just because it exists');
        $this->assertSame(0, $row->confidential, 'sensitivity is decided per document, not assumed');
    }

    public function test_a_retention_period_may_be_absent(): void
    {
        // Null means "keep indefinitely", which is a different promise from
        // five years — so the column has to accept the null rather than
        // defaulting it to a number a statutory expert never agreed to.
        $id = $this->insertType(['retention_months' => null]);

        $this->assertNull(DB::table('document_types')->where('id', $id)->value('retention_months'));
    }

    private function insertEmployee(): int
    {
        $id = DB::table('employees')->insertGetId([
            'name' => 'Katherine Johnson',
            // `employee_code` is unique and mandatory, and there is no `email`
            // column at all: the login address lives on `users`, and
            // `personal_email` is deliberately a *different* one (P2.2).
            'employee_code' => 'EMP-'.strtoupper(self::token()),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) $id;
    }

    private function insertType(array $overrides = []): int
    {
        $id = DB::table('document_types')->insertGetId([
            'name' => 'National ID',
            'slug' => 'national_id',
            'category' => 'identity',
            'is_active' => true,
            'is_system' => true,
            'position' => 10,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);

        return (int) $id;
    }

    private function insertDocument(array $overrides = []): int
    {
        $id = DB::table('employee_documents')->insertGetId([
            'employee_id' => $this->insertEmployee(),
            'title' => 'Passport',
            'file_disk' => 'local',
            'file_path' => 'hrms/1/1/'.self::token().'.pdf',
            'original_name' => 'passport.pdf',
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);

        return (int) $id;
    }

    private static function token(): string
    {
        return substr(bin2hex(random_bytes(6)), 0, 10);
    }
}
