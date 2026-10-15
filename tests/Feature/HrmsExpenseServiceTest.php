<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Document\DocumentType;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Expense\ExpenseCategory;
use App\Models\Hrms\Expense\ExpenseClaim;
use App\Models\Hrms\Payroll\PayrollRun;
use App\Models\Hrms\Payroll\SalaryComponent;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Hrms\Compensation\CompensationService;
use App\Services\Hrms\Expense\ExpenseService;
use App\Services\Hrms\Payroll\PayrollService;
use App\Services\Hrms\Shared\ApprovalService;
use Illuminate\Validation\ValidationException;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P11.2 — claims from filing to reimbursement.
 *
 * Totals recompute server-side, receipts gate lines by category threshold,
 * submission locks the figures, decisions need a resolved chain and never
 * come from the owner, and payroll reimburses approved period claims
 * exactly once — a recalculation pays nothing twice.
 */
class HrmsExpenseServiceTest extends TestCase
{
    use IsolatesDatabase;

    public function test_filing_totals_lines_and_gates_receipts(): void
    {
        $employee = $this->makeEmployee();
        $travel = ExpenseCategory::query()->where('slug', 'travel')->firstOrFail();
        $service = app(ExpenseService::class);

        // Travel always needs a receipt: a bare line is refused.
        try {
            $service->create($employee, $this->claimData(), [
                ['category_id' => $travel->id, 'description' => 'Flight.', 'amount' => '400'],
            ]);
            $this->fail('A receipt-less travel line filed.');
        } catch (ValidationException) {
        }

        $receipt = $this->receiptFor($employee);

        $claim = $service->create($employee, $this->claimData(), [
            ['category_id' => $travel->id, 'description' => 'Flight.', 'amount' => '400', 'receipt_document_id' => $receipt->id],
            ['category_id' => null, 'description' => 'Tip.', 'amount' => '50'],
        ]);

        $this->assertSame('450.00', (string) $claim->total_amount);
        $this->assertMatchesRegularExpression('/^EXP-2026-\d{6}$/', $claim->claim_number);
        $this->assertSame('draft', $claim->status->value);
    }

    public function test_submission_locks_and_routes(): void
    {
        [$manager, $employee] = $this->reportingLine();
        $service = app(ExpenseService::class);

        $claim = $service->create($employee, $this->claimData(), [
            ['description' => 'Stationery.', 'amount' => '30'],
        ]);
        $service->setItems($claim->refresh(), [
            ['description' => 'Stationery.', 'amount' => '35'],
        ]);

        $this->assertSame('35.00', (string) $claim->refresh()->total_amount);

        $submitted = $service->submit($claim->refresh(), $employee->user);

        $this->assertSame('submitted', $submitted->status->value);
        $this->assertNotNull($submitted->approval_id);

        // Locked: no more edits after submission.
        try {
            $service->setItems($submitted, [['description' => 'More.', 'amount' => '5']]);
            $this->fail('A submitted claim edited.');
        } catch (ValidationException) {
        }

        // The manager hears about it.
        $this->assertTrue(UserNotification::query()
            ->where('user_id', $manager->user->id)
            ->where('type', 'hrms.expense.submitted')
            ->exists());
    }

    public function test_decisions_need_a_chain_and_never_come_from_the_owner(): void
    {
        [$manager, $employee] = $this->reportingLine();
        $service = app(ExpenseService::class);
        $refused = 0;

        $claim = $service->create($employee, $this->claimData(), [
            ['description' => 'Books.', 'amount' => '120'],
        ]);
        $service->submit($claim->refresh(), $employee->user);

        // Open chain: no decision yet.
        try {
            $service->approve($claim->refresh(), $manager->user);
            $this->fail('An undecided chain approved.');
        } catch (ValidationException) {
            $refused++;
        }

        // The owner never decides their own money.
        try {
            $service->reject($claim->refresh(), $employee->user, 'No.');
            $this->fail('An owner rejected their own claim.');
        } catch (ValidationException) {
            $refused++;
        }

        $this->assertSame(2, $refused);
    }

    public function test_a_reduced_approval_names_its_reason(): void
    {
        [$manager, $employee] = $this->reportingLine();
        $finance = $this->financeUser();
        $service = app(ExpenseService::class);

        $claim = $service->create($employee, $this->claimData(), [
            ['description' => 'Conference.', 'amount' => '500'],
        ]);
        $service->submit($claim->refresh(), $employee->user);
        $this->decideChain($claim->refresh(), $manager->user, $finance);

        // A cut without a reason is refused.
        try {
            $service->approve($claim->refresh(), $finance, '400.00');
            $this->fail('A reason-less cut approved.');
        } catch (ValidationException) {
        }

        $approved = $service->approve($claim->refresh(), $finance, '400.00', 'Hotel over policy.');

        $this->assertSame('approved', $approved->status->value);
        $this->assertSame('400.00', (string) $approved->approved_amount);
        $this->assertTrue(UserNotification::query()
            ->where('user_id', $employee->user->id)
            ->where('type', 'hrms.expense.approved')
            ->exists());
    }

    public function test_a_rejection_says_why(): void
    {
        [$manager, $employee] = $this->reportingLine();
        $finance = $this->financeUser();
        $service = app(ExpenseService::class);

        $claim = $service->create($employee, $this->claimData(), [
            ['description' => 'Gadgets.', 'amount' => '900'],
        ]);
        $service->submit($claim->refresh(), $employee->user);
        $this->decideChain($claim->refresh(), $manager->user, $finance);

        try {
            $service->reject($claim->refresh(), $finance);
            $this->fail('A reason-less rejection stored.');
        } catch (ValidationException) {
        }

        $rejected = $service->reject($claim->refresh(), $finance, 'Personal purchase.');

        $this->assertSame('rejected', $rejected->status->value);
        $this->assertSame('0.00', (string) $rejected->approved_amount);
    }

    public function test_payroll_reimburses_a_period_claim_exactly_once(): void
    {
        [$manager, $employee] = $this->reportingLine();
        $this->price($employee);
        $finance = $this->financeUser();
        $service = app(ExpenseService::class);

        $claim = $service->create($employee, $this->claimData(), [
            ['description' => 'Client dinner.', 'amount' => '200'],
        ]);
        $service->submit($claim->refresh(), $employee->user);
        $this->decideChain($claim->refresh(), $manager->user, $finance);
        $service->approve($claim->refresh(), $finance);

        $run = $this->openRun();
        app(PayrollService::class)->calculate($run);

        $claim->refresh();
        $this->assertSame('paid', $claim->status->value);
        $this->assertSame($run->id, $claim->paid_in_payroll_run_id);
        $this->assertSame('200.00', (string) $claim->reimbursed_amount);

        $payslip = $run->refresh()->payslips()->where('employee_id', $employee->id)->firstOrFail();
        $lines = $payslip->adjustments()->where('reference_type', 'expense_claim')->get();
        $this->assertCount(1, $lines);
        $this->assertSame($claim->id, $lines->first()->reference_id);
        $this->assertSame('37500.00', (string) $payslip->gross_pay);

        // A recalculation pays nothing twice.
        app(PayrollService::class)->calculate($run->refresh());

        $this->assertCount(
            1,
            $run->refresh()->payslips()->where('employee_id', $employee->id)->firstOrFail()
                ->adjustments()->where('reference_type', 'expense_claim')->get(),
        );
        $this->assertTrue(UserNotification::query()
            ->where('user_id', $employee->user->id)
            ->where('type', 'hrms.expense.paid')
            ->exists());
    }

    public function test_a_claim_from_another_period_waits(): void
    {
        [$manager, $employee] = $this->reportingLine();
        $this->price($employee);
        $finance = $this->financeUser();
        $service = app(ExpenseService::class);

        $claim = $service->create($employee, $this->claimData(2026, 7), [
            ['description' => 'July travel.', 'amount' => '150'],
        ]);
        $service->submit($claim->refresh(), $employee->user);
        $this->decideChain($claim->refresh(), $manager->user, $finance);
        $service->approve($claim->refresh(), $finance);

        $run = $this->openRun();
        app(PayrollService::class)->calculate($run);

        $this->assertSame('approved', $claim->refresh()->status->value);
    }

    // ------------------------------------------------------------ helpers

    /**
     * @return array<string, mixed>
     */
    private function claimData(int $year = 2026, int $month = 8): array
    {
        return [
            'claim_date' => '2026-08-10',
            'period_year' => $year,
            'period_month' => $month,
            'purpose' => 'Client work.',
            'currency' => 'USD',
        ];
    }

    /**
     * Drive the two-leg chain to approved: the manager first, then whoever
     * the finance leg names (attached on the fly, so seeding never matters).
     */
    private function decideChain(ExpenseClaim $claim, User $managerUser, User $finance): void
    {
        $approval = $claim->refresh()->approval;

        app(ApprovalService::class)->approve($approval->fresh(), $managerUser);

        $step = $approval->fresh()->currentStepRecord();
        $roleId = $step?->approver_role_id;

        if ($roleId !== null) {
            $finance->roles()->syncWithoutDetaching([(int) $roleId]);
        }

        app(ApprovalService::class)->approve($approval->fresh(), $finance);
    }

    private function financeUser(): User
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        return User::create([
            'name' => "Expense Finance {$sequence}",
            'email' => "expense.finance.{$sequence}@flowsync.test",
            'password' => 'password',
        ]);
    }

    /**
     * @return array{Employee, Employee} Manager and report, each with `->user`.
     */
    private function reportingLine(): array
    {
        $this->connectTenant('acme');

        $managerUser = User::create([
            'name' => 'Expense Manager',
            'email' => 'expense.manager@flowsync.test',
            'password' => 'password',
        ]);
        $manager = Employee::create([
            'employee_code' => 'EMP-EXP-MGR',
            'name' => 'Expense Manager',
            'status' => EmployeeStatus::Active,
            'user_id' => $managerUser->id,
        ]);
        $manager->user = $managerUser;

        $reportUser = User::create([
            'name' => 'Expense Report',
            'email' => 'expense.report@flowsync.test',
            'password' => 'password',
        ]);
        $report = Employee::create([
            'employee_code' => 'EMP-EXP-REP',
            'name' => 'Expense Report',
            'status' => EmployeeStatus::Active,
            'user_id' => $reportUser->id,
            'manager_id' => $manager->id,
        ]);
        $report->user = $reportUser;

        return [$manager, $report];
    }

    private function receiptFor(Employee $employee): EmployeeDocument
    {
        $this->connectTenant('acme');

        return EmployeeDocument::create([
            'employee_id' => $employee->id,
            'document_type_id' => DocumentType::query()->firstOrFail()->id,
            'title' => 'Boarding pass.',
            'file_disk' => 'local',
            'file_path' => 'hrms/receipt.txt',
            'original_name' => 'receipt.txt',
            'mime' => 'text/plain',
            'size' => 8,
        ]);
    }

    private function price(Employee $employee): void
    {
        $this->connectTenant('acme');

        $basic = SalaryComponent::query()->where('code', 'basic')->firstOrFail();
        $hra = SalaryComponent::query()->where('code', 'hra')->firstOrFail();

        $structure = app(CompensationService::class)->createStructure(
            ['name' => 'Expense 6L', 'currency' => 'INR', 'effective_from' => '2026-04-01'],
            [
                ['component_id' => $basic->id, 'value' => 25000, 'sequence' => 10],
                ['component_id' => $hra->id, 'value' => 50, 'sequence' => 20],
            ],
        );

        app(CompensationService::class)->assign($employee, $structure, '600000', '2026-04-01');
    }

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

    private function makeEmployee(): Employee
    {
        static $sequence = 0;

        $sequence++;

        return Employee::create([
            'employee_code' => 'EMP-EXP-'.$sequence,
            'name' => "Expense Employee {$sequence}",
            'status' => EmployeeStatus::Active,
        ]);
    }
}
