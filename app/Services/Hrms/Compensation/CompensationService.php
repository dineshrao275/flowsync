<?php

namespace App\Services\Hrms\Compensation;

use App\Enums\Hrms\CalculationType;
use App\Enums\Hrms\SalaryComponentType;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Payroll\EmployeeSalaryStructure;
use App\Models\Hrms\Payroll\SalaryComponent;
use App\Models\Hrms\Payroll\SalaryStructure;
use App\Models\User;
use App\Services\Hrms\Org\OrgNaming;
use App\Services\HrmsAuditLogger;
use App\Support\Hrms\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Compensation/HRMS — templates, assignments, and CTC math.
 *
 * Structures name the heads; assignments price a person from a date; the
 * revision lifecycle lives in `SalaryRevisionService` (the P5 split rule).
 * Every amount travels as `Money` (minor-unit ints, D2.16.6) — the models
 * carry decimal strings and the service never lets a float touch them in
 * between.
 */
class CompensationService
{
    public function __construct(
        private readonly OrgNaming $naming,
        private readonly HrmsAuditLogger $audit,
    ) {}

    /** @return Collection<int, SalaryStructure> */
    public function structures(): Collection
    {
        return SalaryStructure::query()->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{component_id: int, value?: numeric|null, sequence?: int|null}>  $components
     */
    public function createStructure(array $data, array $components = [], ?User $actor = null): SalaryStructure
    {
        $structure = SalaryStructure::create([
            ...$data,
            'slug' => $this->naming->uniqueSlug(SalaryStructure::class, (string) $data['name']),
        ]);

        foreach ($components as $index => $row) {
            $component = SalaryComponent::find($row['component_id'] ?? null);

            if ($component === null) {
                throw ValidationException::withMessages(['components' => 'A linked component does not exist.']);
            }

            $structure->components()->attach($component->id, [
                'value' => $row['value'] ?? 0,
                'sequence' => $row['sequence'] ?? ($index + 1) * 10,
                'is_override' => true,
            ]);
        }

        $this->audit->log($structure, 'compensation.structure_created', null, [
            'slug' => $structure->slug,
            'components' => count($components),
        ], $actor);

        return $structure->refresh();
    }

    /**
     * Price a person from a date: close the live row the day before,
     * materialise the new one with computed monthlies, and audit the
     * handover. The first assignment has no predecessor to close.
     *
     * @throws ValidationException on an inactive structure or a non-positive CTC
     */
    public function assign(
        Employee $employee,
        SalaryStructure $structure,
        string $ctcAnnual,
        Carbon|string $effectiveFrom,
        ?string $reason = null,
        ?User $actor = null,
    ): EmployeeSalaryStructure {
        if (! $structure->is_active) {
            throw ValidationException::withMessages(['structure_id' => 'That structure is not active.']);
        }

        $ctc = Money::fromDecimal($ctcAnnual, $structure->currency);

        if ($ctc->isNegative() || $ctc->isZero()) {
            throw ValidationException::withMessages(['ctc_annual' => 'A CTC prices positive money.']);
        }

        $from = $effectiveFrom instanceof Carbon ? $effectiveFrom->toDateString() : (string) $effectiveFrom;
        $resolved = $this->ctcToComponents($structure, $ctc->toDecimal());

        return DB::transaction(function () use ($employee, $structure, $ctc, $from, $reason, $actor, $resolved): EmployeeSalaryStructure {
            $this->closeCurrent($employee, Carbon::parse($from)->subDay()->toDateString());

            $row = EmployeeSalaryStructure::create([
                'employee_id' => $employee->id,
                'structure_id' => $structure->id,
                'ctc_annual' => $ctc->toDecimal(),
                'monthly_ctc' => $resolved['monthly_ctc'],
                'gross_monthly' => $resolved['gross_monthly'],
                'effective_from' => $from,
                'reason' => $reason,
                'is_current' => true,
                'approved_by_user_id' => $actor?->id,
            ]);

            $this->audit->log($row, 'compensation.assigned', null, [
                'employee_id' => $employee->id,
                'structure_id' => $structure->id,
                'ctc_annual' => $ctc->toDecimal(),
            ], $actor);

            return $row->refresh();
        });
    }

    /**
     * Resolve a structure against a CTC into monthly heads.
     *
     * Fixed heads read the structure row (falling back to the component
     * default); percentages multiply against annual CTC or the resolved
     * basic; `formula` heads refuse — formulas need an evaluator no phase
     * has built, and resolving one to zero would pay people wrong.
     * `gross_monthly` is monthly CTC minus employer contributions, never a
     * sum: the sum would double-count heads payroll nets separately.
     *
     * @return array{monthly_ctc: string, gross_monthly: string, employer_monthly: string, components: list<array{code: string, name: string, type: string, monthly: string, annual: string}>}
     *
     * @throws ValidationException on a formula head or a circular basic
     */
    public function ctcToComponents(SalaryStructure $structure, string $ctcAnnual): array
    {
        $ctc = Money::fromDecimal($ctcAnnual, $structure->currency);
        $monthly = $ctc->dividedBy('12');

        $basic = $this->resolveBasic($structure, $ctc);
        $employer = Money::zero($ctc->currency);
        $components = [];

        foreach ($structure->components as $component) {
            $amount = match ($component->calculation_type) {
                CalculationType::Fixed => Money::fromDecimal((string) ($component->pivot->value ?? $component->default_value), $ctc->currency),
                CalculationType::PercentageOfCtc => $ctc->percent((string) ($component->pivot->value ?? $component->default_value))->dividedBy('12'),
                CalculationType::PercentageOfBasic => $basic->percent((string) ($component->pivot->value ?? $component->default_value)),
                CalculationType::Formula => throw ValidationException::withMessages([
                    'components' => "The {$component->name} head needs a formula evaluator — price it by hand.",
                ]),
            };

            if ($component->type === SalaryComponentType::EmployerContribution) {
                $employer = $employer->add($amount);
            }

            $components[] = [
                'code' => $component->code,
                'name' => $component->name,
                'type' => $component->type->value,
                'monthly' => $amount->toDecimal(),
                'annual' => $amount->multiply('12')->toDecimal(),
            ];
        }

        return [
            'monthly_ctc' => $monthly->toDecimal(),
            'gross_monthly' => $monthly->sub($employer)->toDecimal(),
            'employer_monthly' => $employer->toDecimal(),
            'components' => $components,
        ];
    }

    /**
     * The basic head, resolved first because percentages hang off it. A
     * basic priced as a percentage of itself is circular — refuse it
     * instead of resolving zero and paying percentages of nothing.
     *
     * @throws ValidationException on a circular or formula basic
     */
    private function resolveBasic(SalaryStructure $structure, Money $ctc): Money
    {
        $basic = $structure->components->firstWhere('code', 'basic');

        if ($basic === null) {
            return Money::zero($ctc->currency);
        }

        $rate = (string) ($basic->pivot->value ?? $basic->default_value);

        return match ($basic->calculation_type) {
            CalculationType::Fixed => Money::fromDecimal($basic->pivot->value ?? $basic->default_value, $ctc->currency),
            CalculationType::PercentageOfCtc => $ctc->percent($rate)->dividedBy('12'),
            CalculationType::PercentageOfBasic => throw ValidationException::withMessages([
                'components' => 'Basic cannot price itself as a percentage of basic.',
            ]),
            CalculationType::Formula => throw ValidationException::withMessages([
                'components' => 'Basic needs a formula evaluator — price it by hand.',
            ]),
        };
    }

    private function closeCurrent(Employee $employee, string $dayBefore): void
    {
        EmployeeSalaryStructure::query()
            ->where('employee_id', $employee->id)
            ->where('is_current', true)
            ->update(['is_current' => false, 'effective_to' => $dayBefore]);
    }
}
