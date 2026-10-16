<?php

namespace Tests\Feature;

use App\Enums\Hrms\LeaveAdjustmentKind;
use App\Models\Hrms\Leave\LeaveAdjustment;
use App\Models\Hrms\Leave\LeaveBalance;
use App\Models\Hrms\Leave\LeaveType;
use App\Models\Tenant;
use App\Services\Hrms\Leave\LeaveBalanceService;
use App\Services\Hrms\Leave\LeaveConsistency;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F0.7 (M-B12) — hrms:leave-consistency finds balance/ledger drift and --repair converges. */
class HrmsLeaveConsistencyTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setAcmeModules(['hrms.core', 'hrms.leave']);
    }

    private function seedDrifted(): array
    {
        $this->loginAdmin();
        $employee = $this->makeEmployee('Drifty');
        $type = LeaveType::query()->where('code', 'annual')->firstOrFail();
        $year = 2026;

        LeaveAdjustment::create([
            'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year,
            'kind' => LeaveAdjustmentKind::Opening->value, 'quantity' => 10, 'created_at' => now(),
        ]);
        app(LeaveBalanceService::class)->rebuildBalance($employee, $type, $year);

        // Corrupt the projection directly: the ledger still says 10.
        LeaveBalance::query()->where('employee_id', $employee->id)->update(['balance' => 3, 'opening' => 3]);

        return [$employee, $type, $year];
    }

    public function test_a_clean_ledger_reports_no_drift(): void
    {
        $this->loginAdmin();
        $employee = $this->makeEmployee('Clean');
        $type = LeaveType::query()->where('code', 'annual')->firstOrFail();
        LeaveAdjustment::create([
            'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => 2026,
            'kind' => LeaveAdjustmentKind::Opening->value, 'quantity' => 4, 'created_at' => now(),
        ]);
        app(LeaveBalanceService::class)->rebuildBalance($employee, $type, 2026);

        $this->assertSame([], app(LeaveConsistency::class)->scan());
    }

    public function test_scan_finds_a_corrupted_balance_and_repair_fixes_it(): void
    {
        [$employee, $type, $year] = $this->seedDrifted();
        $service = app(LeaveConsistency::class);

        $drift = $service->scan();
        $this->assertCount(1, $drift);
        $this->assertSame('drift', $drift[0]['kind']);
        $this->assertSame(['stored' => 3.0, 'expected' => 10.0], $drift[0]['fields']['balance']);

        $this->assertSame(1, $service->repair($drift));
        $this->assertSame([], $service->scan());
        $this->assertSame('10.00', LeaveBalance::query()->where('employee_id', $employee->id)->value('balance'));
    }

    public function test_a_ledger_group_without_a_balance_row_is_reported(): void
    {
        $this->loginAdmin();
        $employee = $this->makeEmployee('Orphan');
        $type = LeaveType::query()->where('code', 'annual')->firstOrFail();
        LeaveAdjustment::create([
            'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => 2026,
            'kind' => LeaveAdjustmentKind::Opening->value, 'quantity' => 6, 'created_at' => now(),
        ]);

        $drift = app(LeaveConsistency::class)->scan();

        $this->assertCount(1, $drift);
        $this->assertSame('missing_balance', $drift[0]['kind']);
    }

    public function test_the_command_exits_non_zero_on_drift_and_zero_after_repair(): void
    {
        $this->seedDrifted();
        $tenantId = Tenant::query()->where('slug', 'acme')->value('id');

        $this->artisan('hrms:leave-consistency', ['--tenant' => $tenantId])->assertExitCode(1);
        $this->artisan('hrms:leave-consistency', ['--tenant' => $tenantId, '--repair' => true])->assertExitCode(0);
        $this->artisan('hrms:leave-consistency', ['--tenant' => $tenantId])->assertExitCode(0);
    }

    public function test_the_command_requires_exactly_one_scope(): void
    {
        $this->artisan('hrms:leave-consistency')->assertExitCode(1);
    }
}
