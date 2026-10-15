<?php

namespace Tests\Feature;

use App\Enums\Hrms\LeaveAdjustmentKind;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveAdjustment;
use App\Models\Hrms\Leave\LeaveBalance;
use App\Models\Hrms\Leave\LeaveBlackout;
use App\Models\Hrms\Leave\LeaveType;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Models\User;
use App\Services\Hrms\Leave\LeaveBalanceService;
use App\Services\Hrms\Leave\LeaveCalendar;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P5.9 — year-end carry-forward / lapse (idempotent, capped), manual signed
 * balance adjustments with a reason, and blackout windows enforced at ask time.
 */
class HrmsLeaveCompletionTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setAcmeModules(['hrms.core', 'hrms.leave']);
    }

    private function type(string $code): LeaveType
    {
        return LeaveType::query()->where('code', $code)->firstOrFail();
    }

    private function seedBalance(int $employeeId, LeaveType $type, int $year, float $days): void
    {
        LeaveAdjustment::create([
            'employee_id' => $employeeId, 'leave_type_id' => $type->id, 'year' => $year,
            'kind' => LeaveAdjustmentKind::Opening->value, 'quantity' => $days, 'created_at' => now(),
        ]);
        app(LeaveBalanceService::class)->rebuildBalance(Employee::findOrFail($employeeId), $type, $year);
    }

    private function closedYear(): int
    {
        return app(LeaveCalendar::class)->leaveYearFor(now()) - 1;
    }

    public function test_rollover_carries_up_to_the_cap_and_lapses_the_rest(): void
    {
        $this->loginAdmin();
        $employee = $this->makeEmployee('Roller');
        $year = $this->closedYear();
        $annual = $this->type('annual');
        $sick = $this->type('sick');
        $this->seedBalance($employee->id, $annual, $year, 12);
        $this->seedBalance($employee->id, $sick, $year, 3);

        $dry = $this->postJson('/api/hrms/leave/rollover', ['year' => $year, 'dry_run' => true])
            ->assertOk()->assertJsonPath('carried', 5)->assertJsonPath('lapsed', 10)->json();
        $this->assertTrue($dry['dry_run']);
        $this->assertSame(0, LeaveAdjustment::query()->where('kind', 'lapse')->count());

        $this->postJson('/api/hrms/leave/rollover', ['year' => $year])->assertCreated()->assertJsonPath('carried', 5);

        $this->assertEquals(5, LeaveBalance::query()->where(['employee_id' => $employee->id, 'leave_type_id' => $annual->id, 'year' => $year + 1])->value('balance'));
        $this->assertEquals(5, LeaveBalance::query()->where(['employee_id' => $employee->id, 'leave_type_id' => $annual->id, 'year' => $year])->value('balance'));
        $this->assertEquals(0, LeaveBalance::query()->where(['employee_id' => $employee->id, 'leave_type_id' => $sick->id, 'year' => $year])->value('balance'));
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'leave.rollover')->exists());

        // Idempotent: a second pass moves nothing.
        $again = $this->postJson('/api/hrms/leave/rollover', ['year' => $year])->assertCreated()->json();
        $this->assertEquals(0, $again['carried']);
        $this->assertSame(1, LeaveAdjustment::query()->where('kind', 'carry_forward')->where('employee_id', $employee->id)->count());
    }

    public function test_an_unfinished_year_cannot_be_rolled(): void
    {
        $this->loginAdmin();

        $this->postJson('/api/hrms/leave/rollover', ['year' => app(LeaveCalendar::class)->leaveYearFor(now())])
            ->assertUnprocessable()->assertJsonValidationErrors('year');
    }

    public function test_manual_adjustment_is_signed_reasoned_and_cannot_overdraw(): void
    {
        $this->loginAdmin();
        $employee = $this->makeEmployee('Adjusted');
        $annual = $this->type('annual');
        $year = (int) now()->format('Y');
        $payload = ['employee_id' => $employee->id, 'leave_type_id' => $annual->id, 'year' => $year];

        $this->postJson('/api/hrms/leave/adjustments', $payload + ['quantity' => 4, 'reason' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->postJson('/api/hrms/leave/adjustments', $payload + ['quantity' => 4, 'reason' => 'Goodwill credit'])->assertCreated();
        $this->assertEquals(4, LeaveBalance::query()->where($payload)->value('balance'));

        $this->postJson('/api/hrms/leave/adjustments', $payload + ['quantity' => -9, 'reason' => 'Correction too big'])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->postJson('/api/hrms/leave/adjustments', $payload + ['quantity' => -1.5, 'reason' => 'Corrected double count'])->assertCreated();
        $this->assertEquals(2.5, LeaveBalance::query()->where($payload)->value('balance'));
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'leave.balance_adjusted')->exists());

        $this->getJson('/api/hrms/leave/ledger?employee_id='.$employee->id)->assertOk()->assertJsonCount(2, 'ledger');
    }

    public function test_adjustments_need_the_manage_permission(): void
    {
        $this->actAs($this->userWith(['hrms.view', 'hrms.leave.view']));

        $this->postJson('/api/hrms/leave/adjustments', [])->assertForbidden();
        $this->postJson('/api/hrms/leave/rollover', ['year' => 2020])->assertForbidden();
        $this->postJson('/api/hrms/leave/blackouts', [])->assertForbidden();
    }

    public function test_a_blackout_blocks_an_overlapping_ask_by_name(): void
    {
        $this->loginAdmin();
        $this->makeEmployee('Blocked', ['user_id' => User::where('email', 'admin@flowsync.test')->value('id')]);
        $annual = $this->type('annual');
        $from = now()->addMonths(2)->next('Monday')->startOfDay();

        $this->postJson('/api/hrms/leave/blackouts', [
            'name' => 'Quarter close', 'from_date' => $from->toDateString(), 'to_date' => $from->copy()->addDays(4)->toDateString(),
        ])->assertCreated();

        $this->postJson('/api/hrms/leave/requests', [
            'leave_type_id' => $annual->id, 'from_date' => $from->toDateString(), 'to_date' => $from->toDateString(), 'reason' => 'Trip',
        ])->assertUnprocessable()->assertJsonValidationErrors('from_date');

        $this->assertStringContainsString('Quarter close', $this->getJson('/api/hrms/leave/blackouts')->assertOk()->json('blackouts.0.name'));

        // A blackout scoped to another leave type does not block this ask.
        LeaveBlackout::query()->delete();
        LeaveBlackout::create(['name' => 'Sick freeze', 'from_date' => $from, 'to_date' => $from, 'leave_type_id' => $this->type('sick')->id]);

        $this->postJson('/api/hrms/leave/requests', [
            'leave_type_id' => $annual->id, 'from_date' => $from->toDateString(), 'to_date' => $from->toDateString(), 'reason' => 'Trip',
        ])->assertJsonMissingValidationErrors('from_date');
    }
}
