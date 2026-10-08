<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P4.1 — the onboarding/offboarding lifecycle tables.
 *
 * The isolation trait migrates every tenant, so this asserts the migration’s
 * own contract: the FK actions, the enum CHECKs, the two uniqueness rules,
 * and that a second run is a no-op — since `tenants:provision` re-runs tenant
 * migrations to repair a database that failed partway.
 *
 * The enum CHECK tests are the deliberate triple with `HrmsEmployeeTablesTest`
 * and `HrmsDocumentTablesTest`: P3.1 proved a table can silently lose its
 * CHECKs on one grammar while keeping them on the other, so a bad value must
 * be refused by the database itself, on whichever grammar the suite runs.
 */
class HrmsLifecycleTablesTest extends TestCase
{
    use IsolatesDatabase;

    private function migration(): object
    {
        return require database_path('migrations/tenant/2026_09_27_000017_create_hrms_lifecycle_tables.php');
    }

    public function test_the_lifecycle_tables_exist_on_a_fresh_tenant(): void
    {
        foreach ($this->tables() as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }

    public function test_there_is_no_tenant_id_column(): void
    {
        foreach ($this->tables() as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'tenant_id'), "{$table}.tenant_id must not exist");
        }
    }

    public function test_an_employee_gets_exactly_one_onboarding_case(): void
    {
        $case = $this->insertCase($this->insertEmployee());

        $this->expectException(QueryException::class);

        $this->insertCase($case['employee_id']);
    }

    public function test_an_employee_may_leave_twice(): void
    {
        // Offboarding has no uniqueness on employee_id on purpose: each exit
        // is its own case with its own clearance, and a rehire who leaves
        // again must not collide with their first departure.
        $employee = $this->insertEmployee();

        $this->insertOffboardingCase($employee);
        $this->insertOffboardingCase($employee);

        $this->assertSame(2, DB::table('offboarding_cases')->where('employee_id', $employee)->count());
    }

    public function test_a_clearance_belongs_to_exactly_one_case(): void
    {
        $case = $this->insertOffboardingCase($this->insertEmployee());
        $this->insertClearance($case);

        $this->expectException(QueryException::class);

        $this->insertClearance($case);
    }

    public function test_deleting_an_employee_deletes_their_cases(): void
    {
        $employee = $this->insertEmployee();
        $onboarding = $this->insertCase($employee);
        $offboarding = $this->insertOffboardingCase($employee);

        // Hard delete, not soft: `employees` uses SoftDeletes, and only the
        // hard path has to clean up — a purged employee must not strand cases
        // nobody can reach.
        DB::table('employees')->where('id', $employee)->delete();

        $this->assertNull(DB::table('onboarding_cases')->where('id', $onboarding['id'])->value('id'));
        $this->assertNull(DB::table('offboarding_cases')->where('id', $offboarding)->value('id'));
        $this->assertSame(0, DB::table('onboarding_case_tasks')->where('case_id', $onboarding['id'])->count());
    }

    public function test_deleting_a_template_orphans_running_cases_rather_than_aborting_them(): void
    {
        // The opposite action from the employee cascade, on purpose. A case
        // carries its own materialised task copies, so deleting the catalogue
        // row it started from must leave the running case alone — aborting
        // every in-flight onboarding because somebody retired a template is
        // the worst kind of “cleanup”.
        $template = $this->insertTemplate();
        $case = $this->insertCase($this->insertEmployee(), $template);
        $task = $this->insertCaseTask($case['id'], ['template_task_id' => $this->insertTemplateTask($template)]);

        DB::table('onboarding_templates')->where('id', $template)->delete();

        $this->assertSame($case['id'], DB::table('onboarding_cases')->where('id', $case['id'])->value('id'));
        $this->assertNull(DB::table('onboarding_cases')->where('id', $case['id'])->value('template_id'));
        $this->assertNull(DB::table('onboarding_case_tasks')->where('id', $task)->value('template_task_id'));
        $this->assertSame($task, DB::table('onboarding_case_tasks')->where('id', $task)->value('id'));
    }

    public function test_deleting_a_document_type_or_document_orphans_the_request(): void
    {
        // The ask outlives both the catalogue row and the file: retiring a
        // type or removing an upload must leave “HR asked, the file is gone”
        // visible, not silently un-ask the question by cascading the row away.
        $request = $this->insertRequest(['document_type_id' => $type = $this->insertDocumentType()]);

        DB::table('document_types')->where('id', $type)->delete();

        $row = DB::table('document_requests')->where('id', $request)->first();

        $this->assertNotNull($row);
        $this->assertNull($row->document_type_id);

        $document = $this->insertDocument($row->employee_id);
        DB::table('document_requests')->where('id', $request)->update(['document_id' => $document, 'status' => 'submitted']);
        DB::table('employee_documents')->where('id', $document)->delete();

        $row = DB::table('document_requests')->where('id', $request)->first();

        $this->assertNotNull($row, 'a request must survive the file it pointed at');
        $this->assertNull($row->document_id);
    }

    public function test_the_template_category_enum_is_enforced_by_the_database(): void
    {
        $this->expectException(QueryException::class);

        $this->insertTemplateTask($this->insertTemplate(), ['category' => 'astrology']);
    }

    public function test_the_case_status_enums_are_enforced_by_the_database(): void
    {
        try {
            $this->insertCase($this->insertEmployee(), null, ['status' => 'almost']);
            $this->fail('A made-up case status must not insert.');
        } catch (QueryException) {
            // Expected: the database refuses, on either grammar.
        }

        $this->expectException(QueryException::class);

        DB::table('document_requests')->insert([
            'employee_id' => $this->insertEmployee(),
            'title' => 'Bogus',
            'status' => 'maybe',
            'source' => 'hr',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_filtered_indexes_exist(): void
    {
        // The compliance and workload queries all filter on these pairs; an
        // index on the id alone narrows to the whole history before the
        // status/date filter is ever applied.
        $this->assertTrue(Schema::hasIndex('onboarding_case_tasks', ['case_id', 'status']));
        $this->assertTrue(Schema::hasIndex('offboarding_case_tasks', ['case_id', 'status']));
        $this->assertTrue(Schema::hasIndex('document_requests', ['employee_id', 'due_date']));
        $this->assertTrue(Schema::hasIndex('document_requests', ['case_type', 'case_id']));
    }

    /**
     * `tenants:provision` re-runs pending migrations to repair a database that
     * failed partway, so a second `up()` has to be a no-op rather than a
     * duplicate-table error that blocks the repair.
     */
    public function test_the_migration_is_safe_to_run_twice(): void
    {
        $slug = 'repeatable-'.self::token();
        $template = $this->insertTemplate(['name' => 'Repeatable', 'slug' => $slug]);

        $this->migration()->up();

        $this->assertSame(1, DB::table('onboarding_templates')->where('slug', $slug)->count());
        $this->assertSame($template, DB::table('onboarding_templates')->where('slug', $slug)->value('id'));
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return [
            'onboarding_templates',
            'onboarding_template_tasks',
            'onboarding_cases',
            'onboarding_case_tasks',
            'offboarding_cases',
            'offboarding_case_tasks',
            'exit_clearances',
            'document_requests',
        ];
    }

    private function insertEmployee(): int
    {
        return (int) DB::table('employees')->insertGetId([
            'employee_code' => 'EMP-'.strtoupper(self::token()),
            'name' => 'Lifecycle Person',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertTemplate(array $overrides = []): int
    {
        $token = self::token();

        return (int) DB::table('onboarding_templates')->insertGetId([
            'name' => 'Test Template '.$token,
            'slug' => 'test-template-'.$token,
            'is_active' => true,
            'is_system' => false,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    private function insertTemplateTask(int $template, array $overrides = []): int
    {
        return (int) DB::table('onboarding_template_tasks')->insertGetId([
            'template_id' => $template,
            'title' => 'Collect documents',
            'category' => 'document',
            'owner_scope' => 'hr',
            'due_offset_days' => 0,
            'is_mandatory' => true,
            'position' => 10,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    /**
     * @return array{id: int, employee_id: int}
     */
    private function insertCase(int $employee, ?int $template = null, array $overrides = []): array
    {
        $id = (int) DB::table('onboarding_cases')->insertGetId([
            'employee_id' => $employee,
            'template_id' => $template,
            'status' => 'not_started',
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);

        return ['id' => $id, 'employee_id' => $employee];
    }

    private function insertCaseTask(int $case, array $overrides = []): int
    {
        return (int) DB::table('onboarding_case_tasks')->insertGetId([
            'case_id' => $case,
            'title' => 'Upload passport',
            'category' => 'document',
            'owner_scope' => 'employee',
            'status' => 'pending',
            'position' => 10,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    private function insertOffboardingCase(int $employee): int
    {
        return (int) DB::table('offboarding_cases')->insertGetId([
            'employee_id' => $employee,
            'last_working_day' => now()->addMonth()->toDateString(),
            'reason' => 'resigned',
            'status' => 'initiated',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertClearance(int $case): int
    {
        return (int) DB::table('exit_clearances')->insertGetId([
            'case_id' => $case,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertDocumentType(): int
    {
        $token = self::token();

        return (int) DB::table('document_types')->insertGetId([
            'name' => 'Lifecycle Type '.$token,
            'slug' => 'lifecycle-type-'.$token,
            'category' => 'identity',
            'is_active' => true,
            'is_system' => false,
            'position' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertDocument(int $employee): int
    {
        return (int) DB::table('employee_documents')->insertGetId([
            'employee_id' => $employee,
            'title' => 'Lifecycle file',
            'file_disk' => 'local',
            'file_path' => 'hrms/1/'.$employee.'/'.self::token().'.pdf',
            'original_name' => 'file.pdf',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertRequest(array $overrides = []): int
    {
        return (int) DB::table('document_requests')->insertGetId([
            'employee_id' => $this->insertEmployee(),
            'title' => 'Bring your passport',
            'status' => 'pending',
            'source' => 'hr',
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    private static function token(): string
    {
        return substr(bin2hex(random_bytes(6)), 0, 10);
    }
}
