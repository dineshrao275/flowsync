<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P18.1 — the scheduled report table.
 *
 * The isolation trait migrates every tenant, so this asserts the
 * migration's own contract: the table with no `tenant_id`, the slug
 * unique (one schedule per name, re-runs update rather than duplicate),
 * and that a second run is a no-op.
 */
class HrmsReportScheduleTablesTest extends TestCase
{
    use IsolatesDatabase;

    private function migration(): object
    {
        return require database_path('migrations/tenant_hrms/2026_10_10_000033_create_hrms_report_schedules_table.php');
    }

    public function test_the_table_exists_on_a_fresh_tenant(): void
    {
        $this->assertTrue(Schema::hasTable('hrms_report_schedules'));
    }

    public function test_there_is_no_tenant_id_column(): void
    {
        $this->assertFalse(Schema::hasColumn('hrms_report_schedules', 'tenant_id'));
    }

    public function test_schedule_slugs_are_unique(): void
    {
        DB::table('hrms_report_schedules')->insert(['name' => 'Weekly', 'slug' => 'weekly']);

        $this->expectException(QueryException::class);

        DB::table('hrms_report_schedules')->insert(['name' => 'Weekly Again', 'slug' => 'weekly']);
    }

    public function test_a_second_migration_run_is_a_no_op(): void
    {
        $this->migration()->up();

        $this->assertTrue(Schema::hasTable('hrms_report_schedules'));
    }
}
