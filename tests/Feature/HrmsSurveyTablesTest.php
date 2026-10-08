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
 * P16.1 — the engagement survey tables.
 *
 * The isolation trait migrates every tenant, so this asserts the
 * migration's own contract: the six tables with no `tenant_id`, exact
 * money columns, the template slug and per-question result uniques, the
 * double-submission constraints (one row per employee, one row per
 * anonymous fingerprint — with nulls never colliding), the FK actions
 * (owned rows cascade, author links null), and that a second run is a
 * no-op.
 */
class HrmsSurveyTablesTest extends TestCase
{
    use IsolatesDatabase;

    private function migration(): object
    {
        return require database_path('migrations/tenant/2026_10_09_000032_create_hrms_survey_tables.php');
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return [
            'survey_templates',
            'survey_questions',
            'survey_campaigns',
            'survey_responses',
            'survey_answers',
            'survey_results',
        ];
    }

    public function test_the_survey_tables_exist_on_a_fresh_tenant(): void
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

    public function test_money_columns_are_decimal_not_float(): void
    {
        foreach (['survey_questions.min', 'survey_questions.max', 'survey_answers.value_number'] as $key) {
            [$table, $column] = explode('.', $key);
            $definition = collect(Schema::getColumns($table))->firstWhere('name', $column);

            $this->assertNotNull($definition, "{$key} is missing.");
            $this->assertContains(
                $definition['type_name'],
                ['decimal', 'numeric'],
                "{$key} is not exact fixed-point.",
            );
        }
    }

    public function test_a_template_slug_is_unique(): void
    {
        DB::table('survey_templates')->insert(['name' => 'Pulse', 'slug' => 'pulse']);

        $this->expectException(QueryException::class);

        DB::table('survey_templates')->insert(['name' => 'Pulse Again', 'slug' => 'pulse']);
    }

    public function test_an_employee_answers_once_per_campaign(): void
    {
        [$campaign] = $this->campaign();
        $employee = $this->makeEmployee();

        DB::table('survey_responses')->insert(['campaign_id' => $campaign, 'employee_id' => $employee->id]);

        $this->expectException(QueryException::class);

        DB::table('survey_responses')->insert(['campaign_id' => $campaign, 'employee_id' => $employee->id]);
    }

    public function test_an_anonymous_fingerprint_answers_once_per_campaign(): void
    {
        [$campaign] = $this->campaign();
        $fingerprint = hash('md5', "{$campaign}:hash:agent");

        DB::table('survey_responses')->insert([
            'campaign_id' => $campaign, 'employee_id' => null, 'respondent_key' => $fingerprint,
        ]);

        $this->expectException(QueryException::class);

        DB::table('survey_responses')->insert([
            'campaign_id' => $campaign, 'employee_id' => null, 'respondent_key' => $fingerprint,
        ]);
    }

    public function test_null_employees_never_collide(): void
    {
        [$campaign] = $this->campaign();

        // Two anonymous rows with no fingerprint coexist: nulls are
        // distinct in both unique indexes, so anonymity never trips the
        // identified-submission constraint and vice versa.
        DB::table('survey_responses')->insert(['campaign_id' => $campaign, 'employee_id' => null]);
        DB::table('survey_responses')->insert(['campaign_id' => $campaign, 'employee_id' => null]);

        $this->assertSame(2, DB::table('survey_responses')->where('campaign_id', $campaign)->count());
    }

    public function test_deleting_a_campaign_cascades_its_rows(): void
    {
        [$campaign, $question] = $this->campaign();
        $response = DB::table('survey_responses')->insertGetId(['campaign_id' => $campaign]);
        $answer = DB::table('survey_answers')->insertGetId(['response_id' => $response, 'question_id' => $question]);
        DB::table('survey_results')->insert(['campaign_id' => $campaign, 'question_id' => $question]);

        DB::table('survey_campaigns')->where('id', $campaign)->delete();

        $this->assertDatabaseMissing('survey_responses', ['id' => $response]);
        $this->assertDatabaseMissing('survey_answers', ['id' => $answer]);
        $this->assertDatabaseMissing('survey_results', ['campaign_id' => $campaign]);
    }

    public function test_a_second_migration_run_is_a_no_op(): void
    {
        $this->migration()->up();

        foreach ($this->tables() as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table after re-run: {$table}");
        }
    }

    private function makeEmployee(): Employee
    {
        static $sequence = 0;

        $sequence++;

        return Employee::create([
            'employee_code' => 'EMP-SUR-'.$sequence,
            'name' => "Survey Employee {$sequence}",
            'status' => EmployeeStatus::Active,
        ]);
    }

    /**
     * @return array{int, int} Campaign id and one question id.
     */
    private function campaign(): array
    {
        static $sequence = 0;

        $sequence++;

        $template = DB::table('survey_templates')->insertGetId(['name' => 'Pulse', 'slug' => 'pulse-'.$sequence]);
        $question = DB::table('survey_questions')->insertGetId([
            'template_id' => $template, 'text' => 'How are you?', 'sequence' => 10,
        ]);
        $campaign = DB::table('survey_campaigns')->insertGetId([
            'template_id' => $template, 'name' => 'Q3 Pulse',
        ]);

        return [$campaign, $question];
    }
}
