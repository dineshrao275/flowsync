<?php

namespace Tests\Feature;

use App\Enums\Hrms\DataAccessAction;
use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Attendance\AttendanceDay;
use App\Models\Hrms\Document\DocumentType;
use App\Models\Hrms\Document\EmployeeDocument;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Payroll\PayrollRun;
use App\Models\Hrms\Payroll\Payslip;
use App\Models\Hrms\Payroll\SalaryComponent;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Models\Hrms\Shared\HrmsDataAccessLog;
use App\Services\Hrms\Compensation\CompensationService;
use App\Services\Hrms\Retention\HrmsRetention;
use App\Services\HrmsAuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P19.4 — retention reports by default and purges only when asked.
 *
 * Each table ages on its own clock (payslips/documents/access rows by
 * creation, exits by last activity on closed runs, attendance by work
 * date); a bare run reports without writing; `--apply` deletes in batches
 * with document bytes leaving storage first; and the append-only
 * `hrms_audit_logs` is never a candidate on either path.
 */
class HrmsRetentionTest extends TestCase
{
    use IsolatesDatabase;

    public function test_a_bare_run_reports_without_writing(): void
    {
        $this->seedOld();
        $before = $this->counts();

        $this->artisan('hrms:retention', ['--tenant' => $this->acme()->id])
            ->assertSuccessful();

        $this->assertSame($before, $this->counts());
        $this->assertSame(1, app(HrmsRetention::class)->report()['payslips']);
    }

    public function test_apply_purges_old_rows_and_keeps_the_ledger(): void
    {
        $fixtures = $this->seedOld();
        // Seeding itself writes ledger rows (pricing logs its decisions);
        // the purge must change none of them.
        $auditRows = HrmsAuditLog::query()->count();

        $this->artisan('hrms:retention', ['--tenant' => $this->acme()->id, '--apply' => true])
            ->assertSuccessful();

        // Past-window rows are gone on all five tables.
        $this->assertSame(1, Payslip::query()->count());
        $this->assertSame(1, DB::table('offboarding_cases')->count());
        $this->assertSame(1, AttendanceDay::query()->count());
        $this->assertSame(1, EmployeeDocument::query()->count());
        $this->assertSame(0, HrmsDataAccessLog::query()->count());

        // The open exit run survives regardless of age; the ledger survives
        // by design; bytes left storage with their rows.
        $this->assertTrue(DB::table('offboarding_cases')->where('id', $fixtures['open_case'])->exists());
        // Physical absence, not soft-deletion: a purge that leaves PII
        // behind a flag is not a purge.
        $this->assertFalse(DB::table('offboarding_cases')->where('id', $fixtures['old_case'])->exists());
        $this->assertSame(0, DB::table('employee_documents')->where('title', 'Retention Old')->count());
        $this->assertSame($auditRows, HrmsAuditLog::query()->count());
        $this->assertFalse(Storage::disk('local')->exists($fixtures['old_path']));
        $this->assertTrue(Storage::disk('local')->exists($fixtures['new_path']));

        // Idempotent: a second sweep purges nothing new.
        $this->assertSame(
            ['payslips' => 0, 'exits' => 0, 'attendance' => 0, 'documents' => 0, 'data_access' => 0],
            app(HrmsRetention::class)->purge(),
        );
    }

    public function test_months_override_narrows_the_window(): void
    {
        $this->connectTenant('acme');
        $employee = $this->employee('EMP-RET-MO', 'Retention Months');

        AttendanceDay::create([
            'employee_id' => $employee->id,
            'work_date' => now()->subMonths(13)->toDateString(),
            'status' => 'present',
            'worked_minutes' => 480,
        ]);

        $this->assertSame(0, app(HrmsRetention::class)->report()['attendance']);
        $this->assertSame(1, app(HrmsRetention::class)->report(12)['attendance']);
    }

    // ------------------------------------------------------------ helpers

    /**
     * One past-window row (25 months) and one fresh row per table.
     *
     * @return array{open_case: int, old_case: int, old_path: string, new_path: string}
     */
    private function seedOld(): array
    {
        $this->connectTenant('acme');
        $old = now()->subMonths(25);

        $employee = $this->employee('EMP-RET-OLD', 'Retention Old');

        $basic = SalaryComponent::query()->where('code', 'basic')->firstOrFail();
        $structure = app(CompensationService::class)->createStructure(
            ['name' => 'Retention 6L', 'currency' => 'INR', 'effective_from' => '2024-01-01'],
            [['component_id' => $basic->id, 'value' => 25000, 'sequence' => 10]],
        );
        $assignment = app(CompensationService::class)->assign($employee, $structure, '600000', '2024-01-01');

        $run = PayrollRun::create([
            'period_year' => 2024, 'period_month' => 1,
            'pay_period_start' => '2024-01-01', 'pay_period_end' => '2024-01-31',
            'pay_date' => '2024-02-05', 'status' => 'paid',
            'totals' => ['gross_pay' => '50000.00', 'net_pay' => '40000.00'],
        ]);

        $oldSlip = Payslip::create([
            'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
            'employee_salary_structure_id' => $assignment->id,
            'gross_pay' => '50000.00', 'net_pay' => '40000.00',
        ]);
        Payslip::create([
            'payroll_run_id' => $run->id, 'employee_id' => $this->employee('EMP-RET-NEW', 'Retention New')->id,
            'employee_salary_structure_id' => $assignment->id,
            'gross_pay' => '50000.00', 'net_pay' => '40000.00',
        ]);
        $this->backdate('payslips', $oldSlip->id, $old);

        $oldCase = DB::table('offboarding_cases')->insertGetId([
            'employee_id' => $employee->id,
            'last_working_day' => $old->toDateString(),
            'reason' => 'resigned', 'status' => 'completed',
            'created_at' => $old, 'updated_at' => $old,
        ]);
        $openCase = DB::table('offboarding_cases')->insertGetId([
            'employee_id' => $employee->id,
            'last_working_day' => now()->addMonth()->toDateString(),
            'reason' => 'resigned', 'status' => 'initiated',
            'created_at' => $old, 'updated_at' => $old,
        ]);

        AttendanceDay::create([
            'employee_id' => $employee->id,
            'work_date' => $old->toDateString(),
            'status' => 'present', 'worked_minutes' => 480,
        ]);
        AttendanceDay::create([
            'employee_id' => $employee->id,
            'work_date' => today()->toDateString(),
            'status' => 'present', 'worked_minutes' => 480,
        ]);

        $oldPath = "hrms/{$this->acme()->id}/{$employee->id}/retention-old.pdf";
        $newPath = "hrms/{$this->acme()->id}/{$employee->id}/retention-new.pdf";
        Storage::disk('local')->put($oldPath, 'old-bytes');
        Storage::disk('local')->put($newPath, 'new-bytes');

        $oldDoc = $this->document($employee, $oldPath, 'Retention Old');
        $this->document($employee, $newPath, 'Retention New');
        $this->backdate('employee_documents', $oldDoc->id, $old);

        app(HrmsAuditLogger::class)->accessed($employee->getMorphClass(), $employee->id, DataAccessAction::View, ['name']);
        HrmsDataAccessLog::query()->update(['created_at' => $old]);

        app(HrmsAuditLogger::class)->log($employee, 'employee.created');
        HrmsAuditLog::query()->update(['created_at' => $old]);

        return ['open_case' => $openCase, 'old_path' => $oldPath, 'new_path' => $newPath, 'old_case' => $oldCase];
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return [
            'payslips' => Payslip::query()->count(),
            'exits' => DB::table('offboarding_cases')->count(),
            'attendance' => AttendanceDay::query()->count(),
            'documents' => EmployeeDocument::query()->count(),
            'data_access' => HrmsDataAccessLog::query()->count(),
            'audit' => HrmsAuditLog::query()->count(),
        ];
    }

    private function backdate(string $table, int $id, Carbon $when): void
    {
        DB::table($table)->where('id', $id)->update(['created_at' => $when, 'updated_at' => $when]);
    }

    private function employee(string $code, string $name): Employee
    {
        $this->connectTenant('acme');

        return Employee::create([
            'employee_code' => $code, 'name' => $name,
            'status' => EmployeeStatus::Active,
        ]);
    }

    private function document(Employee $employee, string $path, string $title): EmployeeDocument
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');

        $type = DocumentType::create([
            'name' => "Retention Type {$sequence}",
            'slug' => "retention-type-{$sequence}",
            'category' => 'identity',
            'is_mandatory' => false,
            'requires_expiry' => false,
            'retention_months' => null,
            'is_sensitive' => false,
            'position' => $sequence * 10,
            'is_active' => true,
            'is_system' => false,
        ]);

        return EmployeeDocument::create([
            'employee_id' => $employee->id,
            'document_type_id' => $type->id,
            'title' => $title,
            'file_disk' => 'local',
            'file_path' => $path,
            'original_name' => 'retention.pdf',
            'mime' => 'application/pdf',
            'size' => 9,
            'status' => 'pending',
            'visibility' => 'hr',
            'confidential' => false,
            'source' => 'hr',
        ]);
    }
}
