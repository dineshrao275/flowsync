<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Payroll\PayrollRun;
use App\Models\Hrms\Payroll\Payslip;
use App\Models\Hrms\Payroll\SalaryComponent;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Models\Hrms\Shared\HrmsSetting;
use App\Models\Hrms\Statutory\StatutoryConfiguration;
use App\Models\SubscriptionPlan;
use App\Services\Hrms\Compensation\CompensationService;
use App\Services\Hrms\Payroll\PayrollLifecycle;
use App\Services\Hrms\Payroll\PayrollService;
use App\Services\SubscriptionService;
use App\Support\Hrms\Money;
use Illuminate\Validation\ValidationException;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P10.3 — statutory lines on the payslip.
 *
 * The gate and the rulebook each refuse independently: a tenant without the
 * module prices exactly the pre-statutory shape, and so does a tenant with
 * the module but no rulebook. A rulebook prices PF/PT lines into the
 * snapshots and the totals; a later config change moves only a recompute on
 * a review run — locked history never reprices.
 */
class HrmsStatutoryIntegrationTest extends TestCase
{
    use IsolatesDatabase;

    public function test_without_the_module_a_payslip_is_pre_statutory(): void
    {
        $this->setAcmeModules(['hrms.core']);
        $employee = $this->pricedEmployee('US');
        $this->seedConfig('US');

        $run = $this->openRun();
        app(PayrollService::class)->calculate($run);

        $payslip = $run->refresh()->payslips()->firstOrFail();

        $this->assertSame([], $payslip->statutory);
        $this->assertSame('37300.00', (string) $payslip->net_pay);
    }

    public function test_without_a_rulebook_a_payslip_is_pre_statutory(): void
    {
        $this->pricedEmployee('US');

        $run = $this->openRun();
        app(PayrollService::class)->calculate($run);

        $payslip = $run->refresh()->payslips()->firstOrFail();

        $this->assertSame([], $payslip->statutory);
        $this->assertSame('37300.00', (string) $payslip->net_pay);
    }

    public function test_a_rulebook_prices_lines_into_the_snapshot_and_totals(): void
    {
        $this->pricedEmployee('US');
        $this->seedConfig('US');

        $run = $this->openRun();
        app(PayrollService::class)->calculate($run);

        $payslip = $run->refresh()->payslips()->firstOrFail();

        // PT 300 on 37500 gross; PF 12% of capped 15000 basic both sides.
        $codes = collect($payslip->statutory)->pluck('component')->sort()->values()->all();
        $this->assertSame(['pf_employee', 'pf_employer', 'professional_tax'], $codes);

        $this->assertSame('2300.00', (string) $payslip->total_deductions);
        $this->assertSame('35200.00', (string) $payslip->net_pay);

        $employer = collect($payslip->employer_contributions)->firstWhere('code', 'pf_employer');
        $this->assertSame('1800.00', $employer['monthly']);
        $this->assertSame('statutory', $employer['source']);

        $this->assertSame('37500.00', $run->refresh()->totals['gross_pay']);
    }

    public function test_a_region_row_beats_a_country_row(): void
    {
        $this->pricedEmployee('IN');
        $this->seedConfig('IN', null, '150.00');
        $this->seedConfig('IN', 'KA', '350.00', 'in-ka-test');
        $this->settingsRegion('KA');

        $run = $this->openRun();
        app(PayrollService::class)->calculate($run);

        $payslip = $run->refresh()->payslips()->firstOrFail();
        $pt = collect($payslip->statutory)->firstWhere('component', 'professional_tax');

        $this->assertSame('350.00', $pt['amount']);
    }

    public function test_a_recompute_moves_lines_without_repricing_heads(): void
    {
        $employee = $this->pricedEmployee('US');
        $this->seedConfig('US');

        $service = app(PayrollService::class);
        $run = $this->openRun();
        $service->calculate($run);

        $payslip = $run->refresh()->payslips()->firstOrFail();
        $service->addAdjustment($payslip, ['kind' => 'earning', 'label' => 'Bonus', 'amount' => '1000']);

        $this->seedConfig('US', null, '350.00');

        $summary = $service->recomputeStatutory($run->refresh());

        $this->assertSame(['payslips' => 1, 'full' => false], $summary);

        $rebuilt = $run->refresh()->payslips()->firstOrFail();
        $pt = collect($rebuilt->statutory)->firstWhere('component', 'professional_tax');

        $this->assertSame('350.00', $pt['amount']);
        // Base heads untouched, hand-added bonus carried across.
        $this->assertSame('37500.00', $this->baseEarnings($rebuilt));
        $this->assertSame('36150.00', (string) $rebuilt->net_pay);
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'payroll.statutory_recomputed')->exists());
    }

    public function test_locked_history_never_reprices(): void
    {
        $this->pricedEmployee('US');
        $this->seedConfig('US');

        $service = app(PayrollService::class);
        $run = $this->openRun();
        $service->calculate($run);

        $before = $run->refresh()->payslips()->firstOrFail()->getAttributes();

        $lifecycle = app(PayrollLifecycle::class);
        $lifecycle->approve($run->refresh());
        $lifecycle->publish($run->refresh());
        $lifecycle->markPaid($run->refresh());
        $lifecycle->lock($run->refresh());

        $this->seedConfig('US', null, '350.00');

        try {
            $service->recomputeStatutory($run->refresh());
            $this->fail('A locked run accepted a recompute.');
        } catch (ValidationException) {
            // Expected: history is sealed.
        }

        $after = $run->refresh()->payslips()->firstOrFail()->getAttributes();
        $this->assertSame($before['net_pay'], $after['net_pay']);
        $this->assertSame($before['statutory'], $after['statutory']);
    }

    public function test_the_recompute_command_names_its_scope(): void
    {
        $this->pricedEmployee('US');
        $this->seedConfig('US');

        $service = app(PayrollService::class);
        $run = $this->openRun();
        $service->calculate($run);

        $this->artisan('hrms:statutory-recompute', ['--run' => $run->id, '--tenant' => $this->acme()->id])
            ->assertSuccessful();

        $this->artisan('hrms:statutory-recompute', [
            '--run' => $run->id, '--tenant' => $this->acme()->id, '--force' => true,
        ])->assertSuccessful();

        // Neither scope is a footgun; both is a contradiction.
        $this->artisan('hrms:statutory-recompute', ['--run' => $run->id])->assertFailed();
    }

    // ------------------------------------------------------------ helpers

    private function openRun(): PayrollRun
    {
        $this->connectTenant('acme');

        return app(PayrollService::class)->openRun([
            'period_year' => 2026,
            'period_month' => 8,
            'pay_period_start' => '2026-08-01',
            'pay_period_end' => '2026-08-31',
            'pay_date' => '2026-09-05',
        ]);
    }

    /**
     * A US/IN rulebook pricing PT plus a capped 12% PF: the smallest config
     * that exercises both a slab head and a symmetric rate head.
     */
    private function seedConfig(string $country, ?string $region = null, string $ptAmount = '300.00', ?string $code = null): void
    {
        $this->connectTenant('acme');

        StatutoryConfiguration::updateOrCreate(
            ['code' => $code ?? strtolower("test-{$country}")],
            [
                'country' => $country,
                'region' => $region,
                'name' => "Test {$country}".($region === null ? '' : " {$region}"),
                'is_active' => true,
                'config' => [
                'pf' => ['enabled' => true, 'employee_rate' => '12', 'employer_rate' => '12', 'wage_ceiling' => '15000'],
                'professional_tax' => ['enabled' => true, 'slabs' => [
                    ['up_to' => '30000', 'amount' => '150'],
                    ['up_to' => null, 'amount' => $ptAmount],
                ]],
            ],
        ]);
    }

    private function settingsRegion(?string $region): void
    {
        $this->connectTenant('acme');

        HrmsSetting::current()->update(['region' => $region]);
    }

    private function pricedEmployee(string $country): Employee
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        $employee = Employee::create([
            'employee_code' => 'EMP-STI-'.$sequence,
            'name' => "Statutory Integration {$sequence}",
            'status' => EmployeeStatus::Active,
            'country' => $country,
        ]);

        $basic = SalaryComponent::query()->where('code', 'basic')->firstOrFail();
        $hra = SalaryComponent::query()->where('code', 'hra')->firstOrFail();
        $pt = SalaryComponent::query()->where('code', 'professional_tax')->firstOrFail();

        $structure = app(CompensationService::class)->createStructure(
            ['name' => 'Integration 6L', 'currency' => 'INR', 'effective_from' => '2026-04-01'],
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
     * Base earnings total without engine lines: the recompute must move
     * statutory money and nothing else.
     */
    private function baseEarnings(Payslip $payslip): string
    {
        $total = Money::zero();

        foreach ($payslip->earnings as $line) {
            $total = $total->add(Money::fromDecimal((string) $line['monthly']));
        }

        return $total->toDecimal();
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
