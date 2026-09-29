<?php

namespace App\Services\Hrms\Statutory;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Payroll\PayrollRun;
use App\Models\Hrms\Payroll\Payslip;
use App\Models\Hrms\Shared\HrmsSetting;
use App\Models\Hrms\Statutory\StatutoryConfiguration;
use App\Models\Hrms\Statutory\StatutoryDeclaration;
use App\Models\Tenant;
use App\Services\TenantLimits;
use App\Support\Hrms\Money;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Statutory/HRMS — from a pay basis to engine lines, with the gates.
 *
 * The calculator owns arithmetic; this owns jurisdiction: the plan must
 * include `hrms.payroll.statutory` (a tenant with no subscription is
 * unlimited, like every other gate), and a configuration must exist for the
 * employee's country/region. Either answer missing means no lines — Phase 9
 * payslips price identically with the module off, which the integration
 * test pins.
 *
 * Country resolves employee-first, settings-second; region comes from
 * settings (employment records carry no region). A region-specific row
 * beats a country-wide one. The fiscal position (projections, remaining
 * months, deducted-so-far) is computed here so the engine stays
 * stateless — the engine prices a position, this assembles it.
 */
class StatutoryResolver
{
    public function __construct(
        private readonly StatutoryEngine $engine,
        private readonly TenantContext $context,
        private readonly TenantLimits $limits,
    ) {}

    /**
     * Engine lines for one employee's computed base, or nothing when the
     * gate or the rulebook is missing.
     *
     * @param  array{earnings: list<array<string, mixed>>, gross_pay: string}  $base
     * @return list<array{component: string, side: string, amount: string, meta: array<string, mixed>}>
     */
    public function apply(Employee $employee, PayrollRun $run, array $base): array
    {
        $tenant = $this->tenant();

        if ($tenant === null || ! $this->limits->hasModule($tenant, 'hrms.payroll.statutory')) {
            return [];
        }

        $settings = HrmsSetting::current();
        $country = $employee->country ?? $settings->country;
        $configuration = $this->resolveConfig((string) $country, $settings->region);

        if ($configuration === null) {
            return [];
        }

        $config = $configuration->config ?? [];
        $probe = new Payslip(['earnings' => $base['earnings'], 'gross_pay' => $base['gross_pay']]);
        $probe->setRelation('run', $run);

        $payDate = $run->pay_date->copy()->startOfDay();
        $fiscalYear = $this->fiscalYear($payDate, (int) $settings->fiscal_year_start_month);

        $fiscal = [
            'projected_annual_income' => Money::fromDecimal($base['gross_pay'])->multiply('12')->toDecimal(),
            'months_remaining' => $this->monthsRemaining($payDate, (int) $settings->fiscal_year_start_month),
            'tax_deducted_so_far' => $this->deductedSoFar($employee, $payDate, (int) $settings->fiscal_year_start_month),
        ];

        return [
            ...$this->engine->pf($config, $employee, $probe),
            ...$this->engine->esi($config, $employee, $probe),
            ...$this->engine->professionalTax($config, $employee, $probe),
            ...$this->engine->lwf($config, $employee, $probe),
            ...$this->engine->tds($config, $employee, $probe, $this->declarationsFor($employee, $fiscalYear), $fiscal),
        ];
    }

    private function tenant(): ?Tenant
    {
        $tenantId = $this->context->currentId();

        return $tenantId === null ? null : Tenant::find($tenantId);
    }

    /**
     * The active rulebook: region-specific beats country-wide, and either
     * beats nothing. An empty country resolves nothing — guessing a
     * jurisdiction would price the wrong law.
     */
    private function resolveConfig(string $country, ?string $region): ?StatutoryConfiguration
    {
        if ($country === '') {
            return null;
        }

        $query = StatutoryConfiguration::query()->active()->where('country', $country);

        if ($region !== null && $region !== '') {
            $specific = (clone $query)->where('region', $region)->orderBy('id')->first();

            if ($specific !== null) {
                return $specific;
            }
        }

        return (clone $query)->whereNull('region')->orderBy('id')->first();
    }

    /**
     * @return list<array{section: string, amount: string}>
     */
    private function declarationsFor(Employee $employee, int $fiscalYear): array
    {
        return StatutoryDeclaration::query()
            ->where('employee_id', $employee->id)
            ->where('fiscal_year', $fiscalYear)
            ->where('status', 'verified')
            ->get(['section', 'declared_amount'])
            ->map(fn (StatutoryDeclaration $row): array => [
                'section' => (string) $row->section,
                'amount' => (string) $row->declared_amount,
            ])
            ->all();
    }

    /**
     * TDS already priced on this fiscal year's payslips before the pay
     * date: the snapshot is the record, so the deducted-so-far reads the
     * snapshots, not a second ledger that could disagree with them.
     */
    private function deductedSoFar(Employee $employee, Carbon $payDate, int $yearStartMonth): string
    {
        $start = $this->fiscalStart($payDate, $yearStartMonth);
        $total = Money::zero();

        $payslips = Payslip::query()->where('employee_id', $employee->id)
            ->whereHas('run', fn ($query) => $query
                ->whereDate('pay_date', '>=', $start->toDateString())
                ->whereDate('pay_date', '<', $payDate->toDateString()))
            ->get(['statutory']);

        foreach ($payslips as $payslip) {
            foreach ($payslip->statutory ?? [] as $line) {
                if (($line['component'] ?? null) === 'tds') {
                    $total = $total->add(Money::fromDecimal((string) ($line['amount'] ?? '0')));
                }
            }
        }

        return $total->toDecimal();
    }

    /**
     * The fiscal year a date belongs to, labelled by its starting calendar
     * year (2026 for April 2026 – March 2027 under an April year).
     */
    private function fiscalYear(Carbon $date, int $yearStartMonth): int
    {
        return $date->month >= $yearStartMonth ? $date->year : $date->year - 1;
    }

    private function fiscalStart(Carbon $date, int $yearStartMonth): Carbon
    {
        return Carbon::create($this->fiscalYear($date, $yearStartMonth), $yearStartMonth, 1)->startOfDay();
    }

    /**
     * Whole months from the pay month to the fiscal year's last month,
     * inclusive on both ends: a May pay date in an April year leaves
     * eleven (May through March).
     */
    private function monthsRemaining(Carbon $payDate, int $yearStartMonth): int
    {
        $end = $this->fiscalStart($payDate, $yearStartMonth)->addMonths(11)->startOfMonth();

        return $payDate->copy()->startOfMonth()->diffInMonths($end) + 1;
    }
}
