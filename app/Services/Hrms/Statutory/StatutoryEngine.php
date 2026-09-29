<?php

namespace App\Services\Hrms\Statutory;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Payroll\Payslip;
use App\Support\Hrms\Money;

/**
 * Statutory/HRMS — jurisdiction math, pure and table-driven.
 *
 * Every rule reads its rates, ceilings and slabs from the configuration
 * array (a `statutory_configurations.config` row), never from a service
 * constant: the day a government revises a ceiling, the tenant's expert
 * edits a row, not a release. Each method returns
 * `[['component' => slug, 'side' => deduction|employer, 'amount' => decimal,
 * 'meta' => [...]]]` and writes nothing — persistence and snapshots belong
 * to the payroll integration, so a locked payslip can never be recomputed
 * by a later config.
 *
 * Money never touches floats (D2.16.6): config rates SHOULD be decimal
 * strings, and anything else is normalized to four places before it meets
 * Money — a float rate is tolerated at the boundary, never inside.
 *
 * Config shape (all keys optional; absent means that head stays silent):
 * ```
 * [
 *   'pf' => ['enabled', 'employee_rate', 'employer_rate',
 *            'wage_ceiling', 'employee_wage_ceiling' (null = same)],
 *   'esi' => ['enabled', 'employee_rate', 'employer_rate',
 *             'wage_ceiling', 'employee_wage_ceiling' (null = same)],
 *   'professional_tax' => ['enabled', 'slabs' => [['up_to' (null = top), 'amount']]],
 *   'lwf' => ['enabled', 'months' => [ints], 'employee_amount', 'employer_amount'],
 *   'tds' => ['enabled', 'slabs' => [['up_to' (null = top), 'rate' (percent)]]],
 * ]
 * ```
 */
class StatutoryEngine
{
    /**
     * Provident fund on paid basic, capped per side: the employee caps at
     * `employee_wage_ceiling` (falling back to the shared ceiling), the
     * employer at `wage_ceiling`. Zero basic prices zero — no floors.
     *
     * @return list<array{component: string, side: string, amount: string, meta: array<string, mixed>}>
     */
    public function pf(array $config, Employee $employee, Payslip $payslip): array
    {
        $rule = $config['pf'] ?? [];

        if (($rule['enabled'] ?? false) !== true) {
            return [];
        }

        $basic = $this->headMonthly($payslip, 'basic');

        $employeeBase = $this->capped($basic, $rule['employee_wage_ceiling'] ?? $rule['wage_ceiling'] ?? null);
        $employerBase = $this->capped($basic, $rule['wage_ceiling'] ?? null);

        return [
            [
                'component' => 'pf_employee',
                'side' => 'deduction',
                'amount' => $employeeBase->percent($this->rate($rule['employee_rate'] ?? 0))->toDecimal(),
                'meta' => ['wage_base' => $employeeBase->toDecimal()],
            ],
            [
                'component' => 'pf_employer',
                'side' => 'employer',
                'amount' => $employerBase->percent($this->rate($rule['employer_rate'] ?? 0))->toDecimal(),
                'meta' => ['wage_base' => $employerBase->toDecimal()],
            ],
        ];
    }

    /**
     * Employee-state insurance on monthly gross, but only while covered: a
     * gross above the side's ceiling prices nothing (exclusion, not capping
     * — over the line the scheme does not apply at all).
     *
     * @return list<array{component: string, side: string, amount: string, meta: array<string, mixed>}>
     */
    public function esi(array $config, Employee $employee, Payslip $payslip): array
    {
        $rule = $config['esi'] ?? [];

        if (($rule['enabled'] ?? false) !== true) {
            return [];
        }

        $gross = Money::fromDecimal((string) $payslip->gross_pay);
        $lines = [];

        foreach ([
            ['component' => 'esi_employee', 'side' => 'deduction', 'rate' => $rule['employee_rate'] ?? 0, 'ceiling' => $rule['employee_wage_ceiling'] ?? $rule['wage_ceiling'] ?? null],
            ['component' => 'esi_employer', 'side' => 'employer', 'rate' => $rule['employer_rate'] ?? 0, 'ceiling' => $rule['wage_ceiling'] ?? null],
        ] as $leg) {
            if ($leg['ceiling'] !== null && $gross->minor > Money::fromDecimal((string) $leg['ceiling'])->minor) {
                continue;
            }

            $lines[] = [
                'component' => $leg['component'],
                'side' => $leg['side'],
                'amount' => $gross->percent($this->rate($leg['rate']))->toDecimal(),
                'meta' => ['gross' => $gross->toDecimal()],
            ];
        }

        return $lines;
    }

    /**
     * Professional tax from the slab table on monthly gross: the first slab
     * whose `up_to` covers the gross wins, and a null `up_to` is the top
     * slab. No matching slab prices nothing — an incomplete table refuses
     * by staying silent, not by guessing.
     *
     * @return list<array{component: string, side: string, amount: string, meta: array<string, mixed>}>
     */
    public function professionalTax(array $config, Employee $employee, Payslip $payslip): array
    {
        $rule = $config['professional_tax'] ?? [];

        if (($rule['enabled'] ?? false) !== true) {
            return [];
        }

        $gross = Money::fromDecimal((string) $payslip->gross_pay);

        foreach ((array) ($rule['slabs'] ?? []) as $slab) {
            $upTo = $slab['up_to'] ?? null;

            if ($upTo === null || $gross->minor <= Money::fromDecimal((string) $upTo)->minor) {
                return [[
                    'component' => 'professional_tax',
                    'side' => 'deduction',
                    'amount' => Money::fromDecimal((string) ($slab['amount'] ?? 0))->toDecimal(),
                    'meta' => ['gross' => $gross->toDecimal()],
                ]];
            }
        }

        return [];
    }

    /**
     * Labour welfare fund in its months only: flat configured amounts per
     * side, and a zero amount emits no line (a zero line in a snapshot is
     * noise pretending to be a computation).
     *
     * @return list<array{component: string, side: string, amount: string, meta: array<string, mixed>}>
     */
    public function lwf(array $config, Employee $employee, Payslip $payslip): array
    {
        $rule = $config['lwf'] ?? [];

        if (($rule['enabled'] ?? false) !== true) {
            return [];
        }

        $month = (int) ($payslip->run?->period_month ?? 0);

        if (! in_array($month, array_map('intval', (array) ($rule['months'] ?? [])), true)) {
            return [];
        }

        $lines = [];

        foreach ([
            ['component' => 'lwf_employee', 'side' => 'deduction', 'amount' => $rule['employee_amount'] ?? 0],
            ['component' => 'lwf_employer', 'side' => 'employer', 'amount' => $rule['employer_amount'] ?? 0],
        ] as $leg) {
            $amount = Money::fromDecimal((string) $leg['amount']);

            if ($amount->isZero()) {
                continue;
            }

            $lines[] = [
                'component' => $leg['component'],
                'side' => $leg['side'],
                'amount' => $amount->toDecimal(),
                'meta' => ['period_month' => $month],
            ];
        }

        return $lines;
    }

    /**
     * Monthly TDS from the annual picture: slab tax on the projected annual
     * income minus exemptions, less what is already deducted this year,
     * spread over the remaining months. Never negative — an over-deducted
     * year prices zero, not a refund (refunds are the government's, not the
     * payslip's).
     *
     * The fiscal position arrives from the caller (the projection owns it):
     * `projected_annual_income`, `months_remaining`, `tax_deducted_so_far`.
     * Empty means "this payslip's gross times twelve, twelve months left,
     * nothing deducted" — the only stateless reading, and the reason the
     * projection exists.
     *
     * @param  list<array{section?: string, amount?: numeric}>  $declarations
     * @param  array{projected_annual_income?: numeric, months_remaining?: int, tax_deducted_so_far?: numeric}  $fiscal
     * @return list<array{component: string, side: string, amount: string, meta: array<string, mixed>}>
     */
    public function tds(array $config, Employee $employee, Payslip $payslip, array $declarations = [], array $fiscal = []): array
    {
        $rule = $config['tds'] ?? [];

        if (($rule['enabled'] ?? false) !== true || ($rule['slabs'] ?? []) === []) {
            return [];
        }

        $projected = Money::fromDecimal((string) ($fiscal['projected_annual_income']
            ?? Money::fromDecimal((string) $payslip->gross_pay)->multiply('12')->toDecimal()));

        $exempt = Money::zero();
        $sections = [];

        foreach ($declarations as $declaration) {
            $amount = Money::fromDecimal((string) ($declaration['amount'] ?? 0));
            $exempt = $exempt->add($amount);
            $sections[] = (string) ($declaration['section'] ?? '');
        }

        $taxable = $projected->sub($exempt);
        $annual = $this->slabTax($taxable, (array) $rule['slabs']);

        $remaining = max(1, (int) ($fiscal['months_remaining'] ?? 12));
        $deducted = Money::fromDecimal((string) ($fiscal['tax_deducted_so_far'] ?? 0));
        $monthly = $annual->sub($deducted)->dividedBy((string) $remaining);

        if ($monthly->isNegative() || $monthly->isZero()) {
            return [];
        }

        return [[
            'component' => 'tds',
            'side' => 'deduction',
            'amount' => $monthly->toDecimal(),
            'meta' => [
                'projected_annual_income' => $projected->toDecimal(),
                'exemptions' => $exempt->toDecimal(),
                'sections' => array_values(array_unique(array_filter($sections))),
            ],
        ]];
    }

    /**
     * Progressive slab tax: each band prices its own slice at its own rate,
     * so a gross straddling two bands pays both rates on the right slices —
     * never the top rate on the whole.
     *
     * @param  list<array{up_to?: numeric|null, rate?: numeric}>  $slabs
     */
    private function slabTax(Money $taxable, array $slabs): Money
    {
        $tax = Money::zero();
        $floor = Money::zero();

        foreach ($slabs as $slab) {
            $upTo = $slab['up_to'] ?? null;
            $cap = $upTo === null ? null : Money::fromDecimal((string) $upTo);

            if ($cap === null) {
                return $tax->add($taxable->sub($floor)->percent($this->rate($slab['rate'] ?? 0)));
            }

            if ($taxable->minor <= $cap->minor) {
                return $tax->add($taxable->sub($floor)->percent($this->rate($slab['rate'] ?? 0)));
            }

            $tax = $tax->add($cap->sub($floor)->percent($this->rate($slab['rate'] ?? 0)));
            $floor = $cap;
        }

        return $tax;
    }

    /**
     * The paid monthly value of one head code from the earnings snapshot, or
     * zero when the template never priced it. Snapshot, not catalogue: the
     * engine prices what the person was actually paid.
     */
    private function headMonthly(Payslip $payslip, string $code): Money
    {
        foreach ($payslip->earnings ?? [] as $line) {
            if (($line['code'] ?? null) === $code) {
                return Money::fromDecimal((string) ($line['monthly'] ?? '0'));
            }
        }

        return Money::zero();
    }

    /**
     * A wage capped at a ceiling, or uncapped when the config names none. A
     * null ceiling is not a zero ceiling — capping at zero would price every
     * head at nothing the moment a tenant leaves the field empty.
     */
    private function capped(Money $wage, mixed $ceiling): Money
    {
        if ($ceiling === null || $ceiling === '') {
            return $wage;
        }

        $cap = Money::fromDecimal((string) $ceiling);

        return $wage->minor > $cap->minor ? $cap : $wage;
    }

    /**
     * A rate as a decimal string for Money. Strings pass through validated;
     * ints and floats are normalized to four places at this boundary — the
     * only place a float may approach money, and it never gets inside.
     */
    private function rate(mixed $rate): string
    {
        if (is_string($rate) && preg_match('/^-?\d+(\.\d+)?$/', trim($rate))) {
            return trim($rate);
        }

        return number_format((float) $rate, 4, '.', '');
    }
}
