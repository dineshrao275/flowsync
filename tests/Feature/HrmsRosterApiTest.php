<?php

namespace Tests\Feature;

use App\Models\Hrms\Attendance\AttendanceRoster;
use App\Models\Hrms\Attendance\AttendanceShift;
use App\Models\Hrms\Shared\HrmsAuditLog;
use App\Services\Hrms\AttendanceService;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P5.2 — rosters (overlap-replacing assignment, weekly offs, self-service
 * reads) and rotation templates materialised into ordinary roster rows.
 */
class HrmsRosterApiTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setAcmeModules(['hrms.core', 'hrms.shifts', 'hrms.attendance']);
    }

    private function shift(string $code): int
    {
        return (int) AttendanceShift::query()->where('code', $code)->value('id');
    }

    public function test_assigning_splits_the_row_it_lands_inside(): void
    {
        $this->loginAdmin();
        $employee = $this->makeEmployee('Rota Person');

        $this->postJson('/api/hrms/shifts/rosters', [
            'employee_id' => $employee->id, 'shift_id' => $this->shift('general'), 'effective_from' => '2026-11-01',
        ])->assertCreated()->assertJsonPath('roster.weekly_offs', [0, 0, 0, 0, 0, 1, 1]);

        $this->postJson('/api/hrms/shifts/rosters', [
            'employee_id' => $employee->id, 'shift_id' => $this->shift('night'), 'effective_from' => '2026-11-10', 'effective_to' => '2026-11-12',
        ])->assertCreated();

        $rows = AttendanceRoster::query()->where('employee_id', $employee->id)->orderBy('effective_from')->get();

        $this->assertCount(3, $rows);
        $this->assertSame('2026-11-09', $rows[0]->effective_to->toDateString());
        $this->assertSame('2026-11-10', $rows[1]->effective_from->toDateString());
        $this->assertSame('2026-11-13', $rows[2]->effective_from->toDateString());
        $this->assertNull($rows[2]->effective_to);
        $this->assertTrue(HrmsAuditLog::query()->where('action', 'roster.assigned')->exists());
    }

    public function test_an_inactive_shift_and_a_backwards_range_are_refused(): void
    {
        $this->loginAdmin();
        $employee = $this->makeEmployee('Rota Person');
        AttendanceShift::query()->where('code', 'morning')->update(['is_active' => false]);

        $this->postJson('/api/hrms/shifts/rosters', [
            'employee_id' => $employee->id, 'shift_id' => $this->shift('morning'), 'effective_from' => '2026-11-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('shift_id');

        $this->postJson('/api/hrms/shifts/rosters', [
            'employee_id' => $employee->id, 'shift_id' => $this->shift('general'),
            'effective_from' => '2026-11-05', 'effective_to' => '2026-11-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('effective_to');
    }

    public function test_a_rotation_materialises_runs_and_off_days(): void
    {
        $this->loginAdmin();
        $employee = $this->makeEmployee('Rotating');
        $night = $this->shift('night');

        $rotationId = $this->postJson('/api/hrms/shifts/rotations', [
            'name' => '3 on 2 off', 'code' => '3on2off', 'cycle' => [$night, $night, $night, null, null],
        ])->assertCreated()->json('rotation.id');

        $this->postJson("/api/hrms/shifts/rotations/{$rotationId}/apply", [
            'employee_ids' => [$employee->id], 'from' => '2026-12-01', 'to' => '2026-12-10',
        ])->assertCreated()->assertJsonPath('rows', 4);

        $rows = AttendanceRoster::query()->where('employee_id', $employee->id)->orderBy('effective_from')->get();

        $this->assertSame([$night, null, $night, null], $rows->pluck('shift_id')->all());
        $this->assertSame([1, 1, 1, 1, 1, 1, 1], $rows[1]->weekly_offs);

        // Staggering: offset 3 starts the window on the first off day.
        $this->postJson("/api/hrms/shifts/rotations/{$rotationId}/apply", [
            'employee_ids' => [$employee->id], 'from' => '2026-12-01', 'to' => '2026-12-05', 'offset' => 3,
        ])->assertCreated();

        $first = AttendanceRoster::query()->where('employee_id', $employee->id)->orderBy('effective_from')->first();
        $this->assertNull($first->shift_id);
    }

    public function test_a_rotation_day_off_reads_as_a_weekly_off_to_attendance(): void
    {
        $this->loginAdmin();
        $employee = $this->makeEmployee('Off Day');

        $rotationId = $this->postJson('/api/hrms/shifts/rotations', [
            'name' => 'Alt', 'code' => 'alt', 'cycle' => [$this->shift('general'), null],
        ])->assertCreated()->json('rotation.id');

        $this->postJson("/api/hrms/shifts/rotations/{$rotationId}/apply", [
            'employee_ids' => [$employee->id], 'from' => '2026-12-01', 'to' => '2026-12-04',
        ])->assertCreated();

        $reader = app(AttendanceService::class);
        $this->assertTrue($reader->isWeekOff($employee, Carbon::parse('2026-12-02')));
        $this->assertFalse($reader->isWeekOff($employee, Carbon::parse('2026-12-01')));
    }

    public function test_a_rotation_must_have_a_working_day_of_active_shifts(): void
    {
        $this->loginAdmin();

        $this->postJson('/api/hrms/shifts/rotations', ['name' => 'Idle', 'code' => 'idle', 'cycle' => [null, null]])
            ->assertUnprocessable()->assertJsonValidationErrors('cycle');
        $this->postJson('/api/hrms/shifts/rotations', ['name' => 'Ghost', 'code' => 'ghost', 'cycle' => [99999, null]])
            ->assertUnprocessable()->assertJsonValidationErrors('cycle');
    }

    public function test_viewers_read_but_cannot_write_and_people_read_their_own(): void
    {
        $viewer = $this->userWith(['hrms.view', 'hrms.shifts.view']);
        $employee = $this->makeEmployee('Viewer Self', ['user_id' => $viewer->id]);
        AttendanceRoster::create(['employee_id' => $employee->id, 'shift_id' => $this->shift('general'), 'effective_from' => now()->toDateString()]);

        $this->actAs($viewer);
        $this->getJson('/api/hrms/shifts/rosters')->assertOk()->assertJsonCount(1, 'rosters');
        $this->postJson('/api/hrms/shifts/rosters', ['employee_id' => $employee->id, 'effective_from' => '2026-12-01'])->assertForbidden();
        $this->postJson('/api/hrms/shifts/rotations', [])->assertForbidden();

        $plain = $this->userWith(['hrms.view']);
        $this->actAs($plain);
        $this->getJson('/api/hrms/shifts/rosters')->assertForbidden();
        $this->getJson('/api/hrms/shifts/rosters/mine')->assertOk()->assertJsonCount(0, 'rosters');

        $this->actAs($viewer);
        $this->getJson('/api/hrms/shifts/rosters/mine')->assertOk()->assertJsonCount(1, 'rosters');
    }
}
