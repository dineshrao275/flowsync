<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Shared\HrmsAuditLog;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P5.5 — bulk hire from CSV (preview → commit, quota-aware, audited) and bulk
 * status change (dry run, per-person outcomes, terminal states refused).
 */
class HrmsEmployeeBulkTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setAcmeModules(['hrms.core']);
    }

    private function csv(array $lines): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('people.csv', implode("\n", $lines));
    }

    private const HEADER = 'name,personal_email,joining_date,department,designation,location,employment_type,work_mode,manager_code';

    public function test_preview_reports_row_errors_and_writes_nothing(): void
    {
        $this->loginAdmin();
        $before = Employee::count();

        $body = $this->postJson('/api/hrms/employees/import/preview', ['file' => $this->csv([
            self::HEADER,
            'Good Person,good@example.com,2026-11-03,engineering,Specialist,headquarters,full_time,hybrid,',
            ',bad,2026-13-40,nowhere,,,,teleport,NOPE-1',
        ])])->assertOk()->json();

        $this->assertSame(1, $body['valid']);
        $this->assertSame(1, $body['invalid']);
        $this->assertGreaterThanOrEqual(5, count($body['rows'][1]['errors']));
        $this->assertSame($before, Employee::count());
    }

    public function test_commit_creates_rows_links_manager_and_audits(): void
    {
        $this->loginAdmin();
        $manager = $this->makeEmployee('The Boss');

        $this->postJson('/api/hrms/employees/import', ['file' => $this->csv([
            self::HEADER,
            "Asha Rao,asha@example.com,2026-11-03,engineering,Specialist,headquarters,full_time,remote,{$manager->employee_code}",
            'Ravi Kumar,,,sales,,,,,',
        ])])->assertCreated()->assertJsonPath('created', 2);

        $asha = Employee::query()->where('name', 'Asha Rao')->firstOrFail();
        $this->assertSame($manager->id, $asha->manager_id);
        $this->assertNotNull($asha->department_id);
        $this->assertSame('remote', $asha->work_mode->value);
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'employee.bulk_imported')->exists());
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'employee.created')->where('subject_id', $asha->id)->exists());
    }

    public function test_invalid_rows_block_the_import_unless_skipped(): void
    {
        $this->loginAdmin();
        $lines = [self::HEADER, 'Fine One,,,,,,,,', 'Broken,,,ghost-dept,,,,,'];

        $this->postJson('/api/hrms/employees/import', ['file' => $this->csv($lines)])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertSame(0, Employee::query()->where('name', 'Fine One')->count());

        $this->postJson('/api/hrms/employees/import', ['file' => $this->csv($lines), 'skip_invalid' => true])
            ->assertCreated()->assertJsonPath('created', 1)->assertJsonPath('skipped', 1);
    }

    public function test_the_plan_cap_stops_the_whole_batch_before_any_write(): void
    {
        $this->loginAdmin();
        $this->acme()->update(['limits_override' => ['employees' => Employee::count() + 1]]);

        $this->postJson('/api/hrms/employees/import', ['file' => $this->csv([self::HEADER, 'One,,,,,,,,', 'Two,,,,,,,,'])])
            ->assertUnprocessable()->assertJsonValidationErrors('form');

        $this->assertSame(0, Employee::query()->whereIn('name', ['One', 'Two'])->count());
    }

    public function test_import_needs_manage_and_the_csv_needs_a_name_column(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.employees.view']));
        $this->postJson('/api/hrms/employees/import', ['file' => $this->csv([self::HEADER, 'X,,,,,,,,'])])->assertForbidden();
        $target = $this->makeEmployee('Bulk Target');
        $this->postJson('/api/hrms/employees/bulk-status', ['employee_ids' => [$target->id], 'to' => 'active'])->assertForbidden();

        $this->loginAdmin();
        $this->postJson('/api/hrms/employees/import', ['file' => $this->csv(['email', 'a@b.c'])])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_bulk_status_dry_run_then_apply_with_history(): void
    {
        $this->loginAdmin();
        $a = $this->makeEmployee('Bulk A');
        $b = $this->makeEmployee('Bulk B', ['status' => EmployeeStatus::Suspended]);
        $gone = $this->makeEmployee('Bulk Gone', ['status' => EmployeeStatus::Exited]);
        $payload = ['employee_ids' => [$a->id, $b->id, $gone->id], 'to' => 'suspended'];

        $dry = $this->postJson('/api/hrms/employees/bulk-status', $payload + ['dry_run' => true])->assertOk()->json();
        $this->assertSame(['will_change', 'unchanged', 'refused_left'], array_column($dry['results'], 'outcome'));
        $this->assertSame(EmployeeStatus::Active, $a->fresh()->status);

        $this->postJson('/api/hrms/employees/bulk-status', $payload + ['reason' => 'audit'])->assertOk()->assertJsonPath('changed', 1);

        $this->assertSame(EmployeeStatus::Suspended, $a->fresh()->status);
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'employee.bulk_status_changed')->exists());
        $this->assertSame(1, $a->statusHistory()->count());
    }

    public function test_terminal_states_cannot_be_set_in_bulk(): void
    {
        $this->loginAdmin();
        $a = $this->makeEmployee('Stay');

        $this->postJson('/api/hrms/employees/bulk-status', ['employee_ids' => [$a->id], 'to' => 'terminated'])
            ->assertUnprocessable()->assertJsonValidationErrors('to');
    }
}
