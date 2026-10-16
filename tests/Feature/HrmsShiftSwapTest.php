<?php

namespace Tests\Feature;

use App\Models\Hrms\Attendance\AttendanceShift;
use App\Models\Hrms\Attendance\ShiftSwap;
use App\Models\Hrms\Employee\Employee;
use App\Models\User;
use App\Services\Hrms\Shift\ShiftSwapService;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F7.1 — shift swaps: counterparty agrees, the roster is rewritten for that day only. */
class HrmsShiftSwapTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    private User $ann;

    private User $bob;

    private Employee $annEmp;

    private Employee $bobEmp;

    private int $day;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setAcmeModules(['hrms.core', 'hrms.shifts']);

        $this->ann = $this->userWith(['hrms.view']);
        $this->bob = $this->userWith(['hrms.view']);

        $morning = AttendanceShift::create(['name' => 'Morning', 'code' => 'AM', 'start_time' => '08:00', 'end_time' => '16:00', 'break_minutes' => 30, 'grace_minutes' => 10, 'is_active' => true]);
        $evening = AttendanceShift::create(['name' => 'Evening', 'code' => 'PM', 'start_time' => '14:00', 'end_time' => '22:00', 'break_minutes' => 30, 'grace_minutes' => 10, 'is_active' => true]);

        $this->annEmp = $this->makeEmployee('Ann', ['user_id' => $this->ann->id, 'shift_id' => $morning->id]);
        $this->bobEmp = $this->makeEmployee('Bob', ['user_id' => $this->bob->id, 'shift_id' => $evening->id]);
        $this->date = now()->addDays(3)->toDateString();
    }

    private function ask(): array
    {
        $this->actAs($this->ann);

        return $this->postJson('/api/hrms/shift-swaps', [
            'counterparty_employee_id' => $this->bobEmp->id,
            'swap_date' => $this->date,
            'reason' => 'Dentist',
        ])->assertCreated()->json('swap');
    }

    public function test_the_counterparty_agreeing_swaps_both_days(): void
    {
        $swap = $this->ask();
        $this->assertSame('pending', $swap['status']);

        $this->actAs($this->bob);
        $done = $this->postJson("/api/hrms/shift-swaps/{$swap['id']}/approve")->assertOk()->json('swap');

        $this->assertSame('applied', $done['status']);
        $service = app(ShiftSwapService::class);
        $day = now()->addDays(3);
        $this->assertSame($swap['counterparty_shift_id'], $service->shiftOn($this->annEmp->fresh(), $day));
        $this->assertSame($swap['requester_shift_id'], $service->shiftOn($this->bobEmp->fresh(), $day));
        // Only that day moved.
        $this->assertSame($swap['requester_shift_id'], $service->shiftOn($this->annEmp->fresh(), $day->copy()->addDay()));
    }

    public function test_only_the_counterparty_can_decide(): void
    {
        $swap = $this->ask();

        $this->actAs($this->ann);
        $this->postJson("/api/hrms/shift-swaps/{$swap['id']}/approve")->assertStatus(422);
    }

    public function test_rejecting_needs_a_reason_and_changes_nothing(): void
    {
        $swap = $this->ask();

        $this->actAs($this->bob);
        $this->postJson("/api/hrms/shift-swaps/{$swap['id']}/reject", ['note' => ''])->assertStatus(422);
        $this->postJson("/api/hrms/shift-swaps/{$swap['id']}/reject", ['note' => 'Cannot make it'])->assertOk()->assertJsonPath('swap.status', 'rejected');
        $this->assertSame($swap['requester_shift_id'], app(ShiftSwapService::class)->shiftOn($this->annEmp->fresh(), now()->addDays(3)));
    }

    public function test_same_shift_or_past_day_or_self_is_refused(): void
    {
        $this->actAs($this->ann);
        $this->postJson('/api/hrms/shift-swaps', ['counterparty_employee_id' => $this->annEmp->id, 'swap_date' => $this->date])->assertStatus(422);
        $this->postJson('/api/hrms/shift-swaps', ['counterparty_employee_id' => $this->bobEmp->id, 'swap_date' => now()->subDay()->toDateString()])->assertStatus(422);
    }

    public function test_the_requester_can_withdraw(): void
    {
        $swap = $this->ask();
        $this->postJson("/api/hrms/shift-swaps/{$swap['id']}/withdraw")->assertOk()->assertJsonPath('swap.status', 'withdrawn');
        $this->assertSame(0, ShiftSwap::query()->where('status', 'pending')->count());
    }
}
