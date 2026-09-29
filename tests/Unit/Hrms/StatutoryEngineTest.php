<?php

namespace Tests\Unit\Hrms;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Payroll\PayrollRun;
use App\Models\Hrms\Payroll\Payslip;
use App\Services\Hrms\Statutory\StatutoryEngine;
use PHPUnit\Framework\TestCase;

/**
 * P10.2 — the jurisdiction engine, pinned without a database.
 *
 * The engine reads unsaved models, so every rule is exercised here as pure
 * math: below, equal-to and above every threshold, disabled rules staying
 * silent, and the negative cases. A government revises a ceiling by editing
 * a config row — these tables prove the row is the only thing the math
 * reads.
 */
class StatutoryEngineTest extends TestCase
{
    private StatutoryEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = new StatutoryEngine;
    }

    // ------------------------------------------------------------ provident fund

    public function test_pf_prices_basic_up_to_the_ceiling(): void
    {
        $config = ['pf' => ['enabled' => true, 'employee_rate' => '12', 'employer_rate' => '12', 'wage_ceiling' => '15000']];

        $lines = $this->engine->pf($config, $this->employee(), $this->payslip('10000'));

        $this->assertSame('1200.00', $lines[0]['amount']);
        $this->assertSame('1200.00', $lines[1]['amount']);
        $this->assertSame('deduction', $lines[0]['side']);
        $this->assertSame('employer', $lines[1]['side']);
    }

    public function test_pf_caps_at_the_ceiling_not_above_it(): void
    {
        $config = ['pf' => ['enabled' => true, 'employee_rate' => '12', 'employer_rate' => '12', 'wage_ceiling' => '15000']];

        $lines = $this->engine->pf($config, $this->employee(), $this->payslip('25000'));

        $this->assertSame('1800.00', $lines[0]['amount']);
        $this->assertSame('1800.00', $lines[1]['amount']);
    }

    public function test_pf_honours_a_separate_employee_ceiling(): void
    {
        $config = ['pf' => [
            'enabled' => true, 'employee_rate' => '12', 'employer_rate' => '12',
            'wage_ceiling' => '15000', 'employee_wage_ceiling' => '10000',
        ]];

        $lines = $this->engine->pf($config, $this->employee(), $this->payslip('12000'));

        $this->assertSame('1200.00', $lines[0]['amount']);
        $this->assertSame('1440.00', $lines[1]['amount']);
    }

    public function test_a_disabled_pf_is_silent(): void
    {
        $this->assertSame([], $this->engine->pf(['pf' => ['enabled' => false]], $this->employee(), $this->payslip('25000')));
        $this->assertSame([], $this->engine->pf([], $this->employee(), $this->payslip('25000')));
    }

    // ------------------------------------------------------------------- esi

    public function test_esi_covers_gross_at_and_below_the_ceiling(): void
    {
        $config = ['esi' => [
            'enabled' => true, 'employee_rate' => '0.75', 'employer_rate' => '3.25', 'wage_ceiling' => '21000',
        ]];

        $below = $this->engine->esi($config, $this->employee(), $this->payslip('25000', '20000'));
        $this->assertSame('150.00', $below[0]['amount']);
        $this->assertSame('650.00', $below[1]['amount']);

        $equal = $this->engine->esi($config, $this->employee(), $this->payslip('25000', '21000'));
        $this->assertCount(2, $equal);
    }

    public function test_esi_excludes_gross_above_the_ceiling(): void
    {
        $config = ['esi' => [
            'enabled' => true, 'employee_rate' => '0.75', 'employer_rate' => '3.25', 'wage_ceiling' => '21000',
        ]];

        // Exclusion, not capping: a rupee over the line leaves the scheme.
        $this->assertSame([], $this->engine->esi($config, $this->employee(), $this->payslip('25000', '21001')));
    }

    // -------------------------------------------------------- professional tax

    public function test_professional_tax_reads_the_first_covering_slab(): void
    {
        $config = ['professional_tax' => ['enabled' => true, 'slabs' => [
            ['up_to' => '0', 'amount' => '0'],
            ['up_to' => '10000', 'amount' => '100'],
            ['up_to' => null, 'amount' => '200'],
        ]]];

        $this->assertSame('0.00', $this->engine->professionalTax($config, $this->employee(), $this->payslip('25000', '0'))[0]['amount']);
        $this->assertSame('100.00', $this->engine->professionalTax($config, $this->employee(), $this->payslip('25000', '5000'))[0]['amount']);
        $this->assertSame('100.00', $this->engine->professionalTax($config, $this->employee(), $this->payslip('25000', '10000'))[0]['amount']);
        $this->assertSame('200.00', $this->engine->professionalTax($config, $this->employee(), $this->payslip('25000', '50000'))[0]['amount']);
    }

    public function test_an_incomplete_slab_table_stays_silent(): void
    {
        $config = ['professional_tax' => ['enabled' => true, 'slabs' => [
            ['up_to' => '10000', 'amount' => '100'],
        ]]];

        $this->assertSame([], $this->engine->professionalTax($config, $this->employee(), $this->payslip('25000', '50000')));
    }

    // ------------------------------------------------------------------- lwf

    public function test_lwf_pays_in_its_months_only(): void
    {
        $config = ['lwf' => [
            'enabled' => true, 'months' => [6, 12], 'employee_amount' => '25', 'employer_amount' => '50',
        ]];

        $june = $this->engine->lwf($config, $this->employee(), $this->payslip('25000', '40000', 6));
        $this->assertSame('25.00', $june[0]['amount']);
        $this->assertSame('50.00', $june[1]['amount']);

        $this->assertSame([], $this->engine->lwf($config, $this->employee(), $this->payslip('25000', '40000', 3)));
    }

    public function test_lwf_skips_zero_lines(): void
    {
        $config = ['lwf' => ['enabled' => true, 'months' => [6], 'employee_amount' => '0', 'employer_amount' => '50']];

        $lines = $this->engine->lwf($config, $this->employee(), $this->payslip('25000', '40000', 6));

        $this->assertCount(1, $lines);
        $this->assertSame('lwf_employer', $lines[0]['component']);
    }

    // ------------------------------------------------------------------- tds

    public function test_tds_spreads_the_annual_picture_over_remaining_months(): void
    {
        $config = ['tds' => ['enabled' => true, 'slabs' => [
            ['up_to' => '250000', 'rate' => '0'],
            ['up_to' => '500000', 'rate' => '5'],
            ['up_to' => null, 'rate' => '10'],
        ]]];

        // 600000 projected − 100000 exemption = 500000 taxable → 12500
        // annual; 2500 already deducted over 10 months left → 1000/month.
        $lines = $this->engine->tds(
            $config,
            $this->employee(),
            $this->payslip('25000', '50000'),
            [['section' => '80c', 'amount' => '100000']],
            ['projected_annual_income' => '600000', 'months_remaining' => 10, 'tax_deducted_so_far' => '2500'],
        );

        $this->assertSame('1000.00', $lines[0]['amount']);
        $this->assertSame('tds', $lines[0]['component']);
        $this->assertSame(['80c'], $lines[0]['meta']['sections']);
    }

    public function test_tds_prices_each_band_on_its_own_slice(): void
    {
        $config = ['tds' => ['enabled' => true, 'slabs' => [
            ['up_to' => '250000', 'rate' => '0'],
            ['up_to' => '500000', 'rate' => '5'],
            ['up_to' => null, 'rate' => '10'],
        ]]];

        // 600000 taxable → 12500 on the middle band + 10000 on the top.
        $lines = $this->engine->tds(
            $config,
            $this->employee(),
            $this->payslip('25000', '50000'),
            [],
            ['projected_annual_income' => '600000', 'months_remaining' => 12, 'tax_deducted_so_far' => '0'],
        );

        $this->assertSame('1875.00', $lines[0]['amount']);
    }

    public function test_tds_never_prices_a_refund(): void
    {
        $config = ['tds' => ['enabled' => true, 'slabs' => [
            ['up_to' => null, 'rate' => '10'],
        ]]];

        $overDeducted = $this->engine->tds(
            $config, $this->employee(), $this->payslip('25000', '50000'), [],
            ['projected_annual_income' => '400000', 'months_remaining' => 6, 'tax_deducted_so_far' => '50000'],
        );
        $this->assertSame([], $overDeducted);

        $overExempted = $this->engine->tds(
            $config, $this->employee(), $this->payslip('25000', '50000'),
            [['section' => '80c', 'amount' => '500000']],
            ['projected_annual_income' => '400000', 'months_remaining' => 12, 'tax_deducted_so_far' => '0'],
        );
        $this->assertSame([], $overExempted);
    }

    public function test_tds_without_slabs_is_silent(): void
    {
        $this->assertSame([], $this->engine->tds(['tds' => ['enabled' => true]], $this->employee(), $this->payslip('25000', '50000')));
        $this->assertSame([], $this->engine->tds([], $this->employee(), $this->payslip('25000', '50000')));
    }

    public function test_float_rates_normalize_like_strings(): void
    {
        $strings = $this->engine->pf(
            ['pf' => ['enabled' => true, 'employee_rate' => '12', 'employer_rate' => '12', 'wage_ceiling' => '15000']],
            $this->employee(), $this->payslip('25000'),
        );
        $floats = $this->engine->pf(
            ['pf' => ['enabled' => true, 'employee_rate' => 12.0, 'employer_rate' => 12.0, 'wage_ceiling' => 15000]],
            $this->employee(), $this->payslip('25000'),
        );

        $this->assertSame($strings[0]['amount'], $floats[0]['amount']);
        $this->assertSame('1800.00', $floats[0]['amount']);
    }

    // --------------------------------------------------------------- fixtures

    private function employee(): Employee
    {
        return new Employee(['name' => 'Statutory Fixture']);
    }

    private function payslip(string $basicMonthly, string $grossMonthly = '50000', int $month = 8): Payslip
    {
        $payslip = new Payslip([
            'earnings' => [['code' => 'basic', 'name' => 'Basic Salary', 'monthly' => $basicMonthly]],
            'gross_pay' => $grossMonthly,
        ]);

        $payslip->setRelation('run', new PayrollRun([
            'period_year' => 2026,
            'period_month' => $month,
        ]));

        return $payslip;
    }
}
