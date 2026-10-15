<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Payroll\EmployeeSalaryStructure;
use App\Models\Hrms\Payroll\SalaryComponent;
use App\Models\Hrms\Payroll\SalaryStructure;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Models\User;
use App\Services\Hrms\Compensation\CompensationService;
use App\Services\Hrms\Compensation\SalaryRevisionService;
use App\Services\Hrms\Shared\ApprovalService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P9.2b — templates, assignments, raises, and CTC math.
 *
 * Every amount travels as minor-unit ints: the assertions pin exact
 * decimal strings, including the cases where floats famously lie. Small
 * raises apply immediately; big ones and all cuts walk a manager-step
 * chain first, and applying materialises the letter beside the numbers.
 */
class HrmsCompensationTest extends TestCase
{
    use IsolatesDatabase;

    public function test_ctc_math_resolves_percentages_off_basic(): void
    {
        $structure = $this->standardStructure();

        $resolved = $this->compensation()->ctcToComponents($structure, '600000');

        $this->assertSame('50000.00', $resolved['monthly_ctc']);
        $this->assertSame('47000.00', $resolved['gross_monthly']);
        $this->assertSame('3000.00', $resolved['employer_monthly']);

        $heads = collect($resolved['components'])->keyBy('code');

        $this->assertSame('25000.00', $heads['basic']['monthly']);
        $this->assertSame('12500.00', $heads['hra']['monthly']);
        $this->assertSame('150000.00', $heads['hra']['annual']);
        $this->assertSame('7500.00', $heads['special_allowance']['monthly']);
        $this->assertSame('3000.00', $heads['pf_employer']['monthly']);
        $this->assertSame('200.00', $heads['professional_tax']['monthly']);
    }

    public function test_a_formula_head_refuses_instead_of_paying_zero(): void
    {
        $structure = $this->standardStructure();
        $formula = SalaryComponent::create([
            'name' => 'Mystery Pay',
            'slug' => 'mystery-pay',
            'type' => 'earning',
            'calculation_type' => 'formula',
        ]);
        $structure->components()->attach($formula->id, ['value' => 0, 'sequence' => 99]);

        try {
            $this->compensation()->ctcToComponents($structure->refresh(), '600000');
            $this->fail('A formula head must 422, not resolve to zero.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('components', $exception->errors());
        }
    }

    public function test_assign_closes_the_live_row_and_materialises_monthlies(): void
    {
        [$manager, $employee] = $this->reportingLine();
        $structure = $this->standardStructure();

        $first = $this->compensation()->assign($employee, $structure, '600000', '2026-04-01', 'Joining CTC.', $manager->user);

        $this->assertSame('50000.00', (string) $first->monthly_ctc);
        $this->assertSame('47000.00', (string) $first->gross_monthly);
        $this->assertTrue($first->is_current);

        $second = $this->compensation()->assign($employee, $structure, '660000', '2026-10-01', 'Mid-year correction.', $manager->user);

        $this->assertFalse($first->refresh()->is_current);
        $this->assertSame('2026-09-30', $first->refresh()->effective_to->toDateString());
        $this->assertTrue($second->is_current);
        $this->assertSame('55000.00', (string) $second->monthly_ctc);
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'compensation.assigned')->exists());
    }

    public function test_a_small_raise_applies_immediately_with_a_letter(): void
    {
        Storage::fake('local');

        [$manager, $employee] = $this->reportingLine();
        $structure = $this->standardStructure();
        $this->compensation()->assign($employee, $structure, '600000', '2026-04-01');

        // +5% under the 10% threshold: no chain, straight through.
        $revision = $this->revisions()->revise($employee, '630000', '2026-10-01', 'Market correction.', $manager->user);

        $this->assertSame('applied', $revision->status->value);
        $this->assertSame('52500.00', (string) EmployeeSalaryStructure::query()->where('is_current', true)->firstOrFail()->monthly_ctc);

        $letter = $revision->refresh()->letter;

        $this->assertNotNull($letter);
        $this->assertSame('revision_letter', $letter->type->slug);
        Storage::disk('local')->assertExists($letter->file_path);
        $this->assertStringContainsString('630000', Storage::disk('local')->get($letter->file_path));
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'compensation.revision_applied')->exists());
    }

    public function test_a_big_raise_waits_for_its_chain(): void
    {
        [$manager, $employee] = $this->reportingLine();
        $structure = $this->standardStructure();
        $this->compensation()->assign($employee, $structure, '600000', '2026-04-01');

        // +20%: a draft with a manager step, unapplied until decided.
        $revision = $this->revisions()->revise($employee, '720000', '2026-10-01', 'Promotion.', $manager->user);

        $this->assertSame('draft', $revision->status->value);
        $this->assertNotNull($revision->approval_id);

        try {
            $this->revisions()->apply($revision->refresh());
            $this->fail('Applying an open chain must 422.');
        } catch (ValidationException) {
            // Expected.
        }

        app(ApprovalService::class)->approve($revision->approval, $manager->user, 'Well earned.');
        $applied = $this->revisions()->apply($revision->refresh(), $manager->user);

        $this->assertSame('applied', $applied->status->value);
        $this->assertSame('60000.00', (string) EmployeeSalaryStructure::query()->where('is_current', true)->firstOrFail()->monthly_ctc);
    }

    public function test_a_cut_always_needs_eyes(): void
    {
        [$manager, $employee] = $this->reportingLine();
        $structure = $this->standardStructure();
        $this->compensation()->assign($employee, $structure, '600000', '2026-04-01');

        // −8.33%: under the threshold by size, but a cut is never silent.
        $revision = $this->revisions()->revise($employee, '550000', '2026-10-01', 'Restructure.', $manager->user);

        $this->assertSame('draft', $revision->status->value);
        $this->assertNotNull($revision->approval_id);
    }

    public function test_revising_without_an_assignment_is_refused(): void
    {
        $employee = $this->makeEmployee('Unpriced');

        $this->expectException(ValidationException::class);

        $this->revisions()->revise($employee, '600000', '2026-10-01');
    }

    public function test_assigning_an_inactive_structure_is_refused(): void
    {
        [, $employee] = $this->reportingLine();
        $structure = $this->standardStructure();
        $structure->update(['is_active' => false]);

        $this->expectException(ValidationException::class);

        $this->compensation()->assign($employee, $structure->refresh(), '600000', '2026-04-01');
    }

    // ------------------------------------------------------------ helpers

    private function compensation(): CompensationService
    {
        return app(CompensationService::class);
    }

    private function revisions(): SalaryRevisionService
    {
        return app(SalaryRevisionService::class);
    }

    /**
     * @return array{Employee, Employee} Manager and report, each with `->user`.
     */
    private function reportingLine(): array
    {
        $managerUser = $this->makeUser();
        $manager = $this->makeEmployee('Pay Manager', ['user_id' => $managerUser->id]);
        $manager->user = $managerUser;

        $reportUser = $this->makeUser();
        $report = $this->makeEmployee('Pay Report', ['user_id' => $reportUser->id, 'manager_id' => $manager->id]);
        $report->user = $reportUser;

        return [$manager, $report];
    }

    private function standardStructure(): SalaryStructure
    {
        $basic = SalaryComponent::query()->where('code', 'basic')->firstOrFail();
        $hra = SalaryComponent::query()->where('code', 'hra')->firstOrFail();
        $special = SalaryComponent::query()->where('code', 'special_allowance')->firstOrFail();
        $pfEmployer = SalaryComponent::query()->where('code', 'pf_employer')->firstOrFail();
        $pfEmployee = SalaryComponent::query()->where('code', 'pf_employee')->firstOrFail();
        $pt = SalaryComponent::query()->where('code', 'professional_tax')->firstOrFail();

        return $this->compensation()->createStructure(
            ['name' => 'Standard 6L', 'currency' => 'INR', 'effective_from' => '2026-04-01'],
            [
                ['component_id' => $basic->id, 'value' => 25000, 'sequence' => 10],
                ['component_id' => $hra->id, 'value' => 50, 'sequence' => 20],
                ['component_id' => $special->id, 'value' => 30, 'sequence' => 30],
                ['component_id' => $pfEmployer->id, 'value' => 12, 'sequence' => 40],
                ['component_id' => $pfEmployee->id, 'value' => 12, 'sequence' => 50],
                ['component_id' => $pt->id, 'value' => 200, 'sequence' => 60],
            ],
        );
    }

    private function makeUser(): User
    {
        static $sequence = 0;

        $sequence++;

        return User::create([
            'name' => "Pay User {$sequence}",
            'email' => "pay.user.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);
    }

    private function makeEmployee(string $name, array $overrides = []): Employee
    {
        static $sequence = 0;

        $sequence++;

        return Employee::create([
            'employee_code' => 'EMP-PAY-'.$sequence,
            'name' => $name,
            'status' => EmployeeStatus::Active,
            ...$overrides,
        ]);
    }
}
