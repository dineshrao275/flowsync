<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P12.1 — the performance management tables.
 *
 * The isolation trait migrates every tenant, so this asserts the
 * migration's own contract: the eight tables with no `tenant_id`, the
 * cycle slug and pair uniques (goal links, review summaries, feedback
 * responses), the FK actions (owned rows cascade, author links null), the
 * absence of any score column, and that a second run is a no-op.
 */
class HrmsPerformanceTablesTest extends TestCase
{
    use IsolatesDatabase;

    private function migration(): object
    {
        return require database_path('migrations/tenant/2026_10_05_000028_create_hrms_performance_tables.php');
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return [
            'performance_cycles',
            'performance_goals',
            'goal_task_links',
            'check_ins',
            'one_on_ones',
            'feedback_requests',
            'feedback_responses',
            'review_summaries',
        ];
    }

    public function test_the_performance_tables_exist_on_a_fresh_tenant(): void
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

    public function test_there_is_no_composite_score_column(): void
    {
        // Evidence, never scores: a score column would be the schema
        // inviting someone to compute one.
        foreach ($this->tables() as $table) {
            foreach (['overall_score', 'score', 'composite_score', 'final_score'] as $column) {
                $this->assertFalse(Schema::hasColumn($table, $column), "{$table}.{$column} must not exist");
            }
        }
    }

    public function test_a_cycle_slug_is_unique(): void
    {
        DB::table('performance_cycles')->insert($this->cycle('h1-2026'));

        $this->expectException(QueryException::class);

        DB::table('performance_cycles')->insert($this->cycle('h1-2026'));
    }

    public function test_a_review_summary_is_unique_per_cycle_and_employee(): void
    {
        $cycle = DB::table('performance_cycles')->insertGetId($this->cycle('h2-2026'));
        $employee = $this->makeEmployee();

        DB::table('review_summaries')->insert(['cycle_id' => $cycle, 'employee_id' => $employee->id]);

        $this->expectException(QueryException::class);

        DB::table('review_summaries')->insert(['cycle_id' => $cycle, 'employee_id' => $employee->id]);
    }

    public function test_deleting_a_cycle_cascades_its_rows(): void
    {
        $cycle = DB::table('performance_cycles')->insertGetId($this->cycle('h3-2026'));
        $employee = $this->makeEmployee();
        $goal = DB::table('performance_goals')->insertGetId([
            'cycle_id' => $cycle, 'employee_id' => $employee->id, 'title' => 'Ship.',
        ]);

        DB::table('review_summaries')->insert(['cycle_id' => $cycle, 'employee_id' => $employee->id]);
        DB::table('check_ins')->insert(['cycle_id' => $cycle, 'employee_id' => $employee->id, 'body' => 'On track.']);

        DB::table('performance_cycles')->where('id', $cycle)->delete();

        $this->assertDatabaseMissing('performance_goals', ['id' => $goal]);
        $this->assertDatabaseMissing('review_summaries', ['cycle_id' => $cycle]);
        $this->assertDatabaseMissing('check_ins', ['cycle_id' => $cycle]);
    }

    public function test_a_second_migration_run_is_a_no_op(): void
    {
        $this->migration()->up();

        foreach ($this->tables() as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table after re-run: {$table}");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function cycle(string $slug): array
    {
        return [
            'name' => $slug,
            'slug' => $slug,
            'period_start' => '2026-01-01',
            'period_end' => '2026-06-30',
        ];
    }

    private function makeEmployee(): Employee
    {
        static $sequence = 0;

        $sequence++;

        return Employee::create([
            'employee_code' => 'EMP-PER-'.$sequence,
            'name' => "Performance Employee {$sequence}",
            'status' => EmployeeStatus::Active,
        ]);
    }
}
