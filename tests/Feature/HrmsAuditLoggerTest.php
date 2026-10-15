<?php

namespace Tests\Feature;

use App\Enums\Hrms\DataAccessAction;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Models\Hrms\Shared\HrmsDataAccessLog;
use App\Models\User;
use App\Models\Workspace;
use App\Services\HrmsAuditLogger;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class HrmsAuditLoggerTest extends TestCase
{
    use IsolatesDatabase;

    private function logger(): HrmsAuditLogger
    {
        return app(HrmsAuditLogger::class);
    }

    private function user(): User
    {
        return User::where('email', 'admin@flowsync.test')->firstOrFail();
    }

    private function subject(): Workspace
    {
        return Workspace::firstOrCreate(
            ['slug' => 'payroll'],
            ['name' => 'Payroll', 'created_by' => $this->user()->id],
        );
    }

    // ------------------------------------------------------------------ audit

    public function test_it_writes_an_audit_row_with_before_and_after(): void
    {
        $subject = $this->subject();

        $log = $this->logger()->log(
            subject: $subject,
            action: 'leave.approved',
            before: ['days' => 5, 'status' => 'pending'],
            after: ['days' => 4, 'status' => 'approved'],
            actor: $this->user(),
        );

        $this->assertInstanceOf(HrmsAuditLog::class, $log);
        $this->assertSame('leave.approved', $log->action);
        $this->assertSame($subject->getMorphClass(), $log->subject_type);
        $this->assertSame($subject->id, $log->subject_id);
        $this->assertSame('pending', $log->data['before']['status']);
        $this->assertSame('approved', $log->data['after']['status']);
        $this->assertSame($this->user()->id, $log->actor_user_id);
    }

    public function test_it_masks_sensitive_fields(): void
    {
        $log = $this->logger()->log(
            subject: $this->subject(),
            action: 'employee.updated',
            before: ['basic_salary' => 90000, 'department' => 'Engineering'],
            after: ['basic_salary' => 95000, 'department' => 'Engineering'],
        );

        $this->assertSame('***', $log->data['before']['basic_salary']);
        $this->assertSame('***', $log->data['after']['basic_salary']);

        // Non-sensitive fields survive: a ledger where everything is masked is
        // useless.
        $this->assertSame('Engineering', $log->data['after']['department']);
    }

    /**
     * The masking rules themselves, as a table.
     *
     * A data provider rather than inline assertions so the false-positive cases
     * (`designation`, `company`) stay visible next to the true positives: a
     * substring match would mask those and quietly gut the audit trail, which
     * is as damaging as leaking a salary.
     */
    public static function maskingProvider(): array
    {
        return [
            // Masked: genuinely sensitive.
            'basic_salary' => ['basic_salary', true],
            'ctc_annual' => ['ctc_annual', true],
            'net_pay' => ['net_pay', true],
            'wages' => ['wages', true],
            'payslip_id' => ['payslip_id', true],
            'bank_account' => ['bank_account', true],
            'account_number' => ['account_number', true],
            'iban' => ['iban', true],
            'pan_number' => ['pan_number', true],
            'uan' => ['uan', true],
            'esi_number' => ['esi_number', true],
            'pf_number' => ['pf_number', true],
            'ifsc_code' => ['ifsc_code', true],
            'aadhaar' => ['aadhaar', true],
            'date_of_birth' => ['date_of_birth', true],
            'dob' => ['dob', true],
            'personal_phone' => ['personal_phone', true],
            'email' => ['email', true],
            'home_address' => ['home_address', true],
            'medical_notes' => ['medical_notes', true],
            'password' => ['password', true],
            'password_hash' => ['password_hash', true],
            'api_token' => ['api_token', true],
            'national_id' => ['national_id', true],

            // camelCase and acronym forms.
            'basicSalary' => ['basicSalary', true],
            'ESI Number' => ['ESI Number', true],
            'PanNumber' => ['PanNumber', true],
            'IFSC' => ['IFSC', true],

            // Kept: contains a sensitive fragment but is not one.
            'designation' => ['designation', false],
            'company' => ['company', false],
            'department' => ['department', false],
            'code' => ['code', false],
            'employee_code' => ['employee_code', false],
            'name' => ['name', false],
            'status' => ['status', false],
        ];
    }

    #[DataProvider('maskingProvider')]
    public function test_the_masking_rules(string $field, bool $shouldMask): void
    {
        $log = $this->logger()->log(
            subject: $this->subject(),
            action: 'employee.updated',
            after: [$field => 'sensitive-or-not'],
        );

        $this->assertSame(
            $shouldMask ? '***' : 'sensitive-or-not',
            $log->data['after'][$field],
            "Field: {$field}",
        );
    }

    public function test_it_masks_nested_structures(): void
    {
        $log = $this->logger()->log(
            subject: $this->subject(),
            action: 'employee.updated',
            after: [
                // A sensitive parent is masked whole, so its subtree — and
                // anything nested under it — never lands in the ledger.
                'bank' => ['account_number' => '1234567890', 'ifsc' => 'TEST0001'],
                'personal' => ['hometown' => 'Pune'],
            ],
        );

        $this->assertSame('***', $log->data['after']['bank']);

        // A harmless parent is walked, so a sensitive child is still caught.
        $this->assertSame('Pune', $log->data['after']['personal']['hometown']);

        $nested = $this->logger()->log(
            subject: $this->subject(),
            action: 'employee.updated',
            after: ['personal' => ['national_id' => 'AB123']],
        );

        $this->assertSame('***', $nested->data['after']['personal']['national_id']);
    }

    public function test_it_masks_inside_numeric_lists(): void
    {
        $log = $this->logger()->log(
            subject: $this->subject(),
            action: 'payslip.issued',
            after: ['entries' => [
                ['type' => 'earning', 'basic_salary' => 5000],
                ['type' => 'deduction', 'tax' => 400],
            ]],
        );

        $this->assertSame('earning', $log->data['after']['entries'][0]['type']);
        $this->assertSame('***', $log->data['after']['entries'][0]['basic_salary']);
    }

    public function test_the_log_line_carries_field_names_not_values(): void
    {
        $captured = [];

        $channel = Mockery::mock(LoggerInterface::class);
        $channel->shouldReceive('info')
            ->once()
            ->andReturnUsing(function (string $message, array $context) use (&$captured): void {
                $captured = ['message' => $message, 'context' => $context];
            });

        Log::shouldReceive('channel')->with('hrms')->andReturn($channel);

        $this->logger()->log(
            subject: $this->subject(),
            action: 'employee.updated',
            before: ['basic_salary' => 90000, 'department' => 'Engineering'],
            after: ['basic_salary' => 95000, 'department' => 'Finance'],
        );

        $this->assertSame('employee.updated', $captured['message']);

        // Only the field that actually changed is named...
        $this->assertSame(['basic_salary', 'department'], $captured['context']['fields']);

        // ...and no value from the record reaches the log stream.
        $serialised = json_encode($captured);
        $this->assertStringNotContainsString('90000', $serialised);
        $this->assertStringNotContainsString('95000', $serialised);
    }

    public function test_for_subject_returns_newest_first_with_the_actor(): void
    {
        $subject = $this->subject();

        $this->logger()->log($subject, 'leave.requested', actor: $this->user());
        $this->logger()->log($subject, 'leave.approved', actor: $this->user());

        $logs = $this->logger()->forSubject($subject)->get();

        $this->assertCount(2, $logs);
        $this->assertSame('leave.approved', $logs->first()->action);
        $this->assertTrue($logs->first()->relationLoaded('actor'));
    }

    public function test_it_records_the_ip_when_given(): void
    {
        $log = $this->logger()->log($this->subject(), 'employee.viewed', ipAddress: '10.0.0.7');

        $this->assertSame('10.0.0.7', $log->ip_address);
    }

    // ---------------------------------------------------------- data access

    public function test_accessed_records_a_read(): void
    {
        $log = $this->logger()->accessed(
            model: 'Payslip',
            recordId: 7,
            action: DataAccessAction::View,
            fields: ['gross', 'net'],
            actor: $this->user(),
        );

        $this->assertInstanceOf(HrmsDataAccessLog::class, $log);
        $this->assertSame('Payslip', $log->model);
        $this->assertSame(7, $log->record_id);
        $this->assertSame(DataAccessAction::View, $log->action);
        $this->assertSame(['gross', 'net'], $log->fields);
    }

    public function test_accessed_never_stores_field_values(): void
    {
        // The whole point of the columns array is the field names; values must
        // not travel into it even if a caller passes them.
        $log = $this->logger()->accessed(
            model: 'Employee',
            recordId: 3,
            action: DataAccessAction::Export,
            fields: ['salary' => 100000],
        );

        $this->assertSame(['salary'], $log->fields);
        $this->assertStringNotContainsString('100000', json_encode($log->fields));
    }

    public function test_export_is_flagged_as_bulk(): void
    {
        $this->assertTrue(DataAccessAction::Export->isBulk());
        $this->assertFalse(DataAccessAction::View->isBulk());
        $this->assertFalse(DataAccessAction::Download->isBulk());
    }

    public function test_the_two_ledgers_are_separate_tables(): void
    {
        $this->logger()->log($this->subject(), 'employee.updated');
        $this->logger()->accessed('Employee', 1, DataAccessAction::View);

        $this->assertSame(1, HrmsAuditLog::count());
        $this->assertSame(1, HrmsDataAccessLog::count());

        // A read is not a change.
        $this->assertSame(0, HrmsAuditLog::where('action', 'data.view')->count());
    }

    public function test_data_access_logs_can_be_scoped_to_an_actor(): void
    {
        $this->logger()->accessed('Employee', 1, DataAccessAction::View, actor: $this->user());
        $this->logger()->accessed('Payslip', 2, DataAccessAction::Download, actor: $this->user());

        $this->assertSame(2, HrmsDataAccessLog::forActor($this->user()->id)->count());
        $this->assertSame(1, HrmsDataAccessLog::forModel('Payslip')->count());
    }
}
