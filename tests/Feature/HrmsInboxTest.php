<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Asset\Asset;
use App\Models\Hrms\Asset\AssetCategory;
use App\Models\Hrms\Attendance\AttendanceDay;
use App\Models\Hrms\Attendance\AttendanceRegularizationRequest;
use App\Models\Hrms\Document\DocumentType;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Lifecycle\DocumentRequest;
use App\Models\Hrms\Lifecycle\OnboardingCaseTask;
use App\Models\Hrms\Payroll\SalaryComponent;
use App\Models\User;
use App\Services\Hrms\Asset\AssetAssignmentService;
use App\Services\Hrms\Asset\AssetService;
use App\Services\Hrms\Compensation\CompensationService;
use App\Services\Hrms\Expense\ExpenseService;
use App\Services\Hrms\InboxService;
use App\Services\Hrms\OnboardingService;
use App\Services\Hrms\Payroll\PayrollService;
use App\Support\TenantContext;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P15.1 — one queue from every pending thing.
 *
 * Approvals, case items, corrections, file asks, unsigned hardware,
 * expiring files and disputed payslips merge with stable keys; reads
 * persist per login (never globally); and a platform super admin reads
 * an empty queue.
 */
class HrmsInboxTest extends TestCase
{
    use IsolatesDatabase;

    public function test_approvals_case_items_and_corrections_land_with_stable_keys(): void
    {
        [$manager, $report] = $this->reportingLine();
        $service = app(ExpenseService::class);

        $claim = $service->create($report, $this->claimData(), [
            ['description' => 'Taxi.', 'amount' => '60'],
        ]);
        $service->submit($claim->refresh(), $report->user);

        $task = $this->onboardingTask($report, 5);

        $day = AttendanceDay::create([
            'employee_id' => $report->id,
            'work_date' => today()->subDays(2)->toDateString(),
            'status' => 'absent',
        ]);
        $correction = AttendanceRegularizationRequest::create([
            'attendance_day_id' => $day->id,
            'employee_id' => $report->id,
            'work_date' => today()->subDays(2)->toDateString(),
            'reason' => 'Missed punch.',
            'status' => 'pending',
        ]);

        $inbox = app(InboxService::class);

        $managerKeys = $inbox->items($manager->user)->pluck('key');
        $this->assertContains("approval:{$claim->refresh()->approval_id}", $managerKeys);
        $this->assertContains("onboarding_task:{$task->id}", $managerKeys);
        $this->assertContains("attendance_reg:{$correction->id}", $managerKeys);

        $reportKeys = $inbox->items($report->user)->pluck('key');
        $this->assertContains("onboarding_task:{$task->id}", $reportKeys);
        $this->assertContains("attendance_reg:{$correction->id}", $reportKeys);
        $this->assertNotContains("approval:{$claim->refresh()->approval_id}", $reportKeys);
    }

    public function test_documents_assets_disputes_and_expiry_land(): void
    {
        [$manager, $report] = $this->reportingLine();
        $inbox = app(InboxService::class);

        $ask = DocumentRequest::create([
            'employee_id' => $report->id,
            'document_type_id' => DocumentType::query()->firstOrFail()->id,
            'title' => 'Bring your passport.',
            'status' => 'pending',
        ]);

        $asset = $this->asset();
        $assignment = app(AssetAssignmentService::class)->assign($asset, $report, 'good');

        $expiring = EmployeeDocument::create([
            'employee_id' => $report->id,
            'document_type_id' => DocumentType::query()->firstOrFail()->id,
            'title' => 'Passport.',
            'file_disk' => 'local',
            'file_path' => 'hrms/passport.txt',
            'original_name' => 'passport.txt',
            'mime' => 'text/plain',
            'size' => 8,
            'expires_at' => today()->addDays(10)->toDateString(),
        ]);

        $this->price($report);
        $run = app(PayrollService::class)->openRun([
            'period_year' => 2026, 'period_month' => 8,
            'pay_period_start' => '2026-08-01', 'pay_period_end' => '2026-08-31',
            'pay_date' => '2026-09-05',
        ]);
        app(PayrollService::class)->calculate($run);
        $payslip = $run->refresh()->payslips()->where('employee_id', $report->id)->firstOrFail();
        $payslip->update(['status' => 'disputed']);

        $reportKeys = $inbox->items($report->user)->pluck('key');
        $this->assertContains("document_request:{$ask->id}", $reportKeys);
        $this->assertContains("asset_assignment:{$assignment->id}", $reportKeys);
        $this->assertContains("payslip:{$payslip->id}", $reportKeys);

        $managerKeys = $inbox->items($manager->user)->pluck('key');
        $this->assertContains("document_expiring:{$expiring->id}", $managerKeys);
    }

    public function test_reads_persist_per_login_never_globally(): void
    {
        [$manager, $report] = $this->reportingLine();
        $inbox = app(InboxService::class);

        $task = $this->onboardingTask($report, 5);
        $key = "onboarding_task:{$task->id}";

        $this->assertSame(1, $inbox->unreadCount($manager->user));
        $this->assertSame(1, $inbox->unreadCount($report->user));

        $this->assertSame(1, $inbox->markRead($manager->user, [$key]));

        // The manager's read changes nothing for the report.
        $this->assertSame(0, $inbox->unreadCount($manager->user));
        $this->assertSame(1, $inbox->unreadCount($report->user));

        $this->assertSame(1, $inbox->markAllRead($report->user));
        $this->assertSame(0, $inbox->unreadCount($report->user));
    }

    public function test_a_platform_super_admin_reads_an_empty_queue(): void
    {
        [$manager, $report] = $this->reportingLine();
        $this->onboardingTask($report, 5);

        // Unsaved on purpose: the branch reads the attribute, never the
        // row — and tenant users carry no such column (it lives centrally).
        $admin = new User([
            'name' => 'Platform',
            'email' => 'platform@flowsync.test',
        ]);
        $admin->is_super_admin = true;

        app(TenantContext::class)->reset();

        try {
            $inbox = app(InboxService::class);

            $this->assertSame(0, $inbox->items($admin)->total());
            $this->assertSame(0, $inbox->unreadCount($admin));
        } finally {
            $this->connectTenant('acme');
        }
    }

    // ------------------------------------------------------------ helpers

    /**
     * @return array<string, mixed>
     */
    private function claimData(): array
    {
        return [
            'claim_date' => '2026-08-10',
            'period_year' => 2026,
            'period_month' => 8,
            'purpose' => 'Client work.',
            'currency' => 'USD',
        ];
    }

    private function onboardingTask(Employee $owner, int $dueInDays): OnboardingCaseTask
    {
        $this->connectTenant('acme');

        $template = app(OnboardingService::class)->createTemplate('Inbox Template', []);
        $case = app(OnboardingService::class)->createCase($owner, $template);

        return OnboardingCaseTask::create([
            'case_id' => $case->id,
            'title' => 'Upload an ID.',
            'category' => 'document',
            'owner_scope' => 'employee',
            'owner_employee_id' => $owner->id,
            'due_date' => today()->addDays($dueInDays)->toDateString(),
            'status' => 'pending',
        ]);
    }

    private function asset(): Asset
    {
        $this->connectTenant('acme');

        return app(AssetService::class)->create([
            'name' => 'Inbox laptop.',
            'category_id' => AssetCategory::query()->firstOrCreate(
                ['slug' => 'laptops'],
                ['name' => 'Laptops'],
            )->id,
            'serial_number' => 'SN-INBOX-1',
        ]);
    }

    private function price(Employee $employee): void
    {
        $this->connectTenant('acme');

        $basic = SalaryComponent::query()->where('code', 'basic')->firstOrFail();
        $hra = SalaryComponent::query()->where('code', 'hra')->firstOrFail();

        $structure = app(CompensationService::class)->createStructure(
            ['name' => 'Inbox 6L', 'currency' => 'INR', 'effective_from' => '2026-04-01'],
            [
                ['component_id' => $basic->id, 'value' => 25000, 'sequence' => 10],
                ['component_id' => $hra->id, 'value' => 50, 'sequence' => 20],
            ],
        );

        app(CompensationService::class)->assign($employee, $structure, '600000', '2026-04-01');
    }

    /**
     * @return array{Employee, Employee} Manager and report, each with `->user`.
     */
    private function reportingLine(): array
    {
        $this->connectTenant('acme');

        $managerUser = User::create([
            'name' => 'Inbox Manager', 'email' => 'inbox.manager@flowsync.test', 'password' => 'password',
        ]);
        $manager = Employee::create([
            'employee_code' => 'EMP-INB-MGR', 'name' => 'Inbox Manager',
            'status' => EmployeeStatus::Active, 'user_id' => $managerUser->id,
        ]);
        $manager->user = $managerUser;

        $reportUser = User::create([
            'name' => 'Inbox Report', 'email' => 'inbox.report@flowsync.test', 'password' => 'password',
        ]);
        $report = Employee::create([
            'employee_code' => 'EMP-INB-REP', 'name' => 'Inbox Report',
            'status' => EmployeeStatus::Active, 'user_id' => $reportUser->id, 'manager_id' => $manager->id,
        ]);
        $report->user = $reportUser;

        return [$manager, $report];
    }
}
