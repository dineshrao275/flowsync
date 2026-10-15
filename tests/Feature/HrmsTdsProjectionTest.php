<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Payroll\SalaryComponent;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Models\Hrms\Statutory\StatutoryConfiguration;
use App\Models\Hrms\Statutory\StatutoryDeclaration;
use App\Models\Hrms\Statutory\TdsProject;
use App\Models\SubscriptionPlan;
use App\Services\Hrms\Compensation\CompensationService;
use App\Services\Hrms\Payroll\PayrollService;
use App\Services\Hrms\Statutory\StatutoryService;
use App\Services\SubscriptionService;
use Illuminate\Validation\ValidationException;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P10.4 — the annual TDS picture.
 *
 * A 6L August payslip annualises to 450000; a verified 100000 exemption
 * leaves 350000 taxable → 5000 annual across the bands → 1250 a quarter.
 * The August payslip's own monthly TDS covers Q3, so only Q1/Q2/Q4 warn;
 * a surrender deposits Q1's shortfall against a challan, and re-projection
 * refreshes estimates without touching the deposit.
 */
class HrmsTdsProjectionTest extends TestCase
{
    use IsolatesDatabase;

    public function test_projection_splits_the_year_and_warns_where_uncovered(): void
    {
        $employee = $this->pricedEmployee();
        $this->declare($employee, 2026, '80c', '100000');
        $this->seedConfig();
        $this->calculateAugust();

        $result = app(StatutoryService::class)->projectTds($employee, 2026);

        $this->assertCount(4, $result['projects']);
        $this->assertSame('1250.00', $result['projects'][0]->tax_liability);
        $this->assertSame('1250.00', $result['projects'][2]->tds_deducted);
        $this->assertSame('0.00', $result['projects'][0]->tds_deducted);

        $warned = collect($result['warnings'])->pluck('quarter')->sort()->values()->all();
        $this->assertSame([1, 2, 4], $warned);
        $this->assertSame('1250.00', $result['warnings'][0]['shortfall']);

        $this->assertTrue(HrmsAuditLog::query()->where('action', 'statutory.tds_projected')->exists());
    }

    public function test_surrender_deposits_the_shortfall_and_reprojection_keeps_it(): void
    {
        $employee = $this->pricedEmployee();
        $this->declare($employee, 2026, '80c', '100000');
        $this->seedConfig();
        $this->calculateAugust();

        $service = app(StatutoryService::class);
        $result = $service->projectTds($employee, 2026);
        $first = $result['projects'][0];

        $surrendered = $service->surrender($first, 'CHALLAN-281-001');

        $this->assertSame('1250.00', (string) $surrendered->tds_surrendered);
        $this->assertSame('CHALLAN-281-001', $surrendered->challan_ref);
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'statutory.tds_surrendered')->exists());

        $again = $service->projectTds($employee, 2026);

        $this->assertCount(4, TdsProject::query()->where('employee_id', $employee->id)->where('fiscal_year', 2026)->get());
        $this->assertSame('CHALLAN-281-001', $again['projects'][0]->challan_ref);
        $this->assertSame('1250.00', (string) $again['projects'][0]->tds_surrendered);

        $warned = collect($again['warnings'])->pluck('quarter')->sort()->values()->all();
        $this->assertSame([2, 4], $warned);
    }

    public function test_surrender_names_its_challan(): void
    {
        $employee = $this->pricedEmployee();
        $this->declare($employee, 2026, '80c', '100000');
        $this->seedConfig();
        $this->calculateAugust();

        $project = app(StatutoryService::class)->projectTds($employee, 2026)['projects'][0];

        $this->expectException(ValidationException::class);

        app(StatutoryService::class)->surrender($project, '  ');
    }

    public function test_projection_refuses_without_data_or_gate_or_rulebook(): void
    {
        $employee = $this->pricedEmployee();
        $this->seedConfig();
        $refused = 0;

        // No payslip in the year: nothing to annualise.
        try {
            app(StatutoryService::class)->projectTds($employee, 2026);
            $this->fail('A dataless year projected.');
        } catch (ValidationException) {
            $refused++;
        }

        $this->calculateAugust();

        // No rulebook for the jurisdiction.
        StatutoryConfiguration::query()->delete();

        try {
            app(StatutoryService::class)->projectTds($employee, 2026);
            $this->fail('A rulebook-less tenant projected.');
        } catch (ValidationException) {
            $refused++;
        }

        // No module on the plan.
        $this->seedConfig();
        $this->setAcmeModules(['hrms.core']);

        try {
            app(StatutoryService::class)->projectTds($employee, 2026);
            $this->fail('A module-less tenant projected.');
        } catch (ValidationException) {
            $refused++;
        }

        // No such fiscal year.
        try {
            app(StatutoryService::class)->projectTds($employee, 1800);
            $this->fail('Year 1800 projected.');
        } catch (ValidationException) {
            $refused++;
        }

        $this->assertSame(4, $refused);
    }

    // ------------------------------------------------------------ helpers

    private function calculateAugust(): void
    {
        $run = app(PayrollService::class)->openRun([
            'period_year' => 2026,
            'period_month' => 8,
            'pay_period_start' => '2026-08-01',
            'pay_period_end' => '2026-08-31',
            'pay_date' => '2026-09-05',
        ]);

        app(PayrollService::class)->calculate($run);
    }

    private function seedConfig(): void
    {
        $this->connectTenant('acme');

        StatutoryConfiguration::updateOrCreate(
            ['code' => 'test-us-tds'],
            [
                'country' => 'US',
                'region' => null,
                'name' => 'Test US TDS',
                'is_active' => true,
                'config' => [
                    'professional_tax' => ['enabled' => true, 'slabs' => [
                        ['up_to' => '30000', 'amount' => '150'],
                        ['up_to' => null, 'amount' => '300'],
                    ]],
                    'tds' => ['enabled' => true, 'slabs' => [
                        ['up_to' => '250000', 'rate' => '0'],
                        ['up_to' => '500000', 'rate' => '5'],
                        ['up_to' => null, 'rate' => '10'],
                    ]],
                ],
            ],
        );
    }

    private function declare(Employee $employee, int $fiscalYear, string $section, string $amount): void
    {
        $this->connectTenant('acme');

        StatutoryDeclaration::create([
            'employee_id' => $employee->id,
            'fiscal_year' => $fiscalYear,
            'section' => $section,
            'declared_amount' => $amount,
            'status' => 'verified',
            'verified_at' => now(),
        ]);
    }

    private function pricedEmployee(): Employee
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        $employee = Employee::create([
            'employee_code' => 'EMP-TDS-'.$sequence,
            'name' => "TDS Employee {$sequence}",
            'status' => EmployeeStatus::Active,
            'country' => 'US',
        ]);

        $basic = SalaryComponent::query()->where('code', 'basic')->firstOrFail();
        $hra = SalaryComponent::query()->where('code', 'hra')->firstOrFail();
        $pt = SalaryComponent::query()->where('code', 'professional_tax')->firstOrFail();

        $structure = app(CompensationService::class)->createStructure(
            ['name' => 'TDS 6L', 'currency' => 'INR', 'effective_from' => '2026-04-01'],
            [
                ['component_id' => $basic->id, 'value' => 25000, 'sequence' => 10],
                ['component_id' => $hra->id, 'value' => 50, 'sequence' => 20],
                ['component_id' => $pt->id, 'value' => 200, 'sequence' => 30],
            ],
        );

        app(CompensationService::class)->assign($employee, $structure, '600000', '2026-04-01');

        return $employee;
    }

    /**
     * @param  list<string>  $modules
     */
    private function setAcmeModules(array $modules): void
    {
        $plan = SubscriptionPlan::where('slug', 'pro')->firstOrFail();
        $plan->update(['limits' => array_merge($plan->limits, ['modules' => $modules])]);

        app(SubscriptionService::class)->assign($this->acme(), $plan->fresh());
    }
}
