<?php

namespace App\Services\Hrms\Statutory;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Payroll\Payslip;
use App\Models\Hrms\Shared\HrmsSetting;
use App\Models\Hrms\Statutory\StatutoryDeclaration;
use App\Models\Hrms\Statutory\TdsProject;
use App\Models\Tenant;
use App\Models\User;
use App\Services\HrmsAuditLogger;
use App\Services\TenantLimits;
use App\Support\Hrms\Money;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Statutory/HRMS — the annual TDS picture per employee.
 *
 * `projectTds()` annualises the last three payslips of the fiscal year,
 * subtracts verified exemptions, prices the annual slab tax through the
 * engine (the same bands the monthly TDS uses), and splits the picture
 * into four quarterly rows with `Money::allocate` — a split that loses no
 * paisa, so four quarters always sum back to the year. Re-projection
 * refreshes the estimates and never touches a surrender: a deposit is a
 * fact, not an estimate.
 *
 * Under-deduction returns as warnings (a quarter still owing after payslip
 * lines and deposits); the run-detail surface in P10.6 renders them, and
 * the operational log carries them here.
 */
class StatutoryService
{
    public function __construct(
        private readonly StatutoryEngine $engine,
        private readonly StatutoryResolver $resolver,
        private readonly TenantContext $context,
        private readonly TenantLimits $limits,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /**
     * Project (or refresh) one employee's four quarterly rows.
     *
     * @return array{projects: list<TdsProject>, warnings: list<array{quarter: int, shortfall: string}>}
     *
     * @throws ValidationException without the module, a rulebook with TDS
     *                             slabs, or any payslip to annualise from
     */
    public function projectTds(Employee $employee, int $fiscalYear, ?User $actor = null): array
    {
        if ($fiscalYear < 1900 || $fiscalYear > 2100) {
            throw ValidationException::withMessages(['fiscal_year' => 'A projection prices one real fiscal year.']);
        }

        $tenant = $this->tenant();

        if ($tenant === null || ! $this->limits->hasModule($tenant, 'hrms.payroll.statutory')) {
            throw ValidationException::withMessages(['form' => 'Statutory is not enabled for this tenant.']);
        }

        $configuration = $this->resolver->configurationFor($employee);
        $slabs = $configuration?->config['tds']['slabs'] ?? [];

        if ($configuration === null || ($configuration->config['tds']['enabled'] ?? false) !== true || $slabs === []) {
            throw ValidationException::withMessages(['form' => 'There is no TDS rulebook for this employee’s jurisdiction.']);
        }

        $settings = HrmsSetting::current();
        $yearStartMonth = (int) $settings->fiscal_year_start_month;

        $payslips = $this->yearPayslips($employee, $fiscalYear, $yearStartMonth);

        if ($payslips->isEmpty()) {
            throw ValidationException::withMessages(['form' => 'There is no payslip in that fiscal year to annualise from.']);
        }

        $projected = $this->annualise($payslips);
        $exempt = $this->verifiedExemptions($employee, $fiscalYear);
        $annual = Money::fromDecimal($this->engine->annualTax($configuration->config, $projected->sub($exempt)->toDecimal()));

        return DB::transaction(function () use ($employee, $fiscalYear, $yearStartMonth, $projected, $exempt, $annual, $actor): array {
            $incomeQuarters = Money::allocate($projected->minor, 4);
            $exemptQuarters = Money::allocate($exempt->minor, 4);
            $taxQuarters = Money::allocate($annual->minor, 4);

            $projects = [];
            $warnings = [];

            for ($quarter = 1; $quarter <= 4; $quarter++) {
                [$from, $to] = $this->quarterRange($fiscalYear, $yearStartMonth, $quarter);

                $project = TdsProject::query()->firstOrNew([
                    'employee_id' => $employee->id,
                    'fiscal_year' => $fiscalYear,
                    'quarter' => $quarter,
                ]);

                $project->fill([
                    'declared_income' => $projected->toDecimal(),
                    'exempt_income' => $exemptQuarters[$quarter - 1]->toDecimal(),
                    'projected_income' => $incomeQuarters[$quarter - 1]->toDecimal(),
                    'tax_liability' => $taxQuarters[$quarter - 1]->toDecimal(),
                    'tds_deducted' => $this->quarterDeducted($employee, $from, $to),
                ]);
                $project->save();

                $projects[] = $project->refresh();

                if ($project->shortfall() !== '0.00') {
                    $warnings[] = ['quarter' => $quarter, 'shortfall' => $project->shortfall()];
                }
            }

            $this->audit->log($projects[0], 'statutory.tds_projected', null, [
                'employee_id' => $employee->id,
                'fiscal_year' => $fiscalYear,
                'warnings' => count($warnings),
            ], $actor);

            Log::channel('hrms')->warning('statutory.tds_under_deducted', [
                'tenant_id' => $this->context->currentId(),
                'employee_id' => $employee->id,
                'fiscal_year' => $fiscalYear,
                // Quarters only, never the shortfall figures: the amounts
                // live in the projection screen (and its access rows), not
                // in a log line that outlives the data it describes.
                'quarters' => array_column($warnings, 'quarter'),
            ]);

            return ['projects' => $projects, 'warnings' => $warnings];
        });
    }

    /**
     * Record a challan deposit against a quarter: the shortfall still owing
     * becomes surrendered, and every surrender audits — including
     * overwrites, so a corrected challan keeps its trail.
     *
     * @throws ValidationException on an empty challan reference
     */
    public function surrender(TdsProject $project, string $challanRef, ?User $actor = null): TdsProject
    {
        $challanRef = trim($challanRef);

        if ($challanRef === '') {
            throw ValidationException::withMessages(['challan_ref' => 'A surrender names its challan.']);
        }

        return DB::transaction(function () use ($project, $challanRef, $actor): TdsProject {
            $project->update([
                'tds_surrendered' => $project->shortfall(),
                'challan_ref' => $challanRef,
            ]);

            $this->audit->log($project->refresh(), 'statutory.tds_surrendered', null, [
                'challan_ref' => $challanRef,
            ], $actor);

            return $project->refresh();
        });
    }

    private function tenant(): ?Tenant
    {
        $tenantId = $this->context->currentId();

        return $tenantId === null ? null : Tenant::find($tenantId);
    }

    /**
     * @return Collection<int, Payslip>
     */
    private function yearPayslips(Employee $employee, int $fiscalYear, int $yearStartMonth): Collection
    {
        $start = Carbon::create($fiscalYear, $yearStartMonth, 1)->startOfDay();
        $end = $start->copy()->addYear();

        return Payslip::query()->where('employee_id', $employee->id)
            ->whereHas('run', fn ($query) => $query
                ->whereDate('pay_date', '>=', $start->toDateString())
                ->whereDate('pay_date', '<', $end->toDateString()))
            ->with('run:id,pay_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The last three payslips' average gross, times twelve. Three, not the
     * whole year: recent pay (raises, arrears) predicts the year better
     * than January does, and fewer than three annualises what exists
     * rather than refusing.
     */
    private function annualise(Collection $payslips): Money
    {
        $recent = $payslips->take(3);
        $total = Money::zero();

        foreach ($recent as $payslip) {
            $total = $total->add(Money::fromDecimal((string) $payslip->gross_pay));
        }

        return $total->dividedBy((string) $recent->count())->multiply('12');
    }

    private function verifiedExemptions(Employee $employee, int $fiscalYear): Money
    {
        $total = Money::zero();

        $rows = StatutoryDeclaration::query()
            ->where('employee_id', $employee->id)
            ->where('fiscal_year', $fiscalYear)
            ->where('status', 'verified')
            ->get(['declared_amount']);

        foreach ($rows as $row) {
            $total = $total->add(Money::fromDecimal((string) $row->declared_amount));
        }

        return $total;
    }

    /**
     * @return array{Carbon, Carbon} Inclusive start, exclusive end.
     */
    private function quarterRange(int $fiscalYear, int $yearStartMonth, int $quarter): array
    {
        $start = Carbon::create($fiscalYear, $yearStartMonth, 1)->startOfDay()->addMonths(($quarter - 1) * 3);

        return [$start, $start->copy()->addMonths(3)];
    }

    /**
     * TDS the payslips priced inside one quarter: the snapshots are the
     * record, so deducted-so-far reads them, not a second ledger.
     */
    private function quarterDeducted(Employee $employee, Carbon $from, Carbon $to): string
    {
        $total = Money::zero();

        $payslips = Payslip::query()->where('employee_id', $employee->id)
            ->whereHas('run', fn ($query) => $query
                ->whereDate('pay_date', '>=', $from->toDateString())
                ->whereDate('pay_date', '<', $to->toDateString()))
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
}
