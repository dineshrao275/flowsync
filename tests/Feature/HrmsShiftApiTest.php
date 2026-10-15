<?php

namespace Tests\Feature;

use App\Models\Hrms\Attendance\AttendanceShift;
use App\Models\Hrms\Shared\HrmsAuditLog;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P5.1 — the shift catalogue over HTTP: starters seeded from the config
 * patterns, CRUD with split/overnight derivation, delete guards, default-shift
 * assignment, and the module/permission gates.
 */
class HrmsShiftApiTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setAcmeModules(['hrms.core', 'hrms.shifts']);
    }

    public function test_the_config_patterns_are_seeded_as_system_shifts(): void
    {
        $codes = AttendanceShift::query()->where('is_system', true)->pluck('code')->all();

        foreach (['general', 'morning', 'night'] as $code) {
            $this->assertContains($code, $codes);
        }

        $night = AttendanceShift::query()->where('code', 'night')->firstOrFail();
        $this->assertTrue($night->is_night);
        $this->assertSame(['mon', 'tue', 'wed', 'thu', 'fri', 'sat'], $night->working_days);
    }

    public function test_reads_need_view_and_writes_need_manage(): void
    {
        $this->actAs($this->userWith(['hrms.view']));
        $this->getJson('/api/hrms/shifts')->assertForbidden();

        $this->actAs($this->userWith(['hrms.view', 'hrms.shifts.view']));
        $this->getJson('/api/hrms/shifts')->assertOk()->assertJsonPath('shifts.0.is_system', true);
        $this->postJson('/api/hrms/shifts', ['name' => 'Late', 'code' => 'late', 'start_time' => '21:00', 'end_time' => '05:00'])->assertForbidden();
    }

    public function test_the_module_gate_blocks_a_plan_without_shifts(): void
    {
        $this->setAcmeModules(['hrms.core']);
        $this->loginAdmin();

        $this->getJson('/api/hrms/shifts')->assertForbidden();
    }

    public function test_create_derives_overnight_and_audits(): void
    {
        $this->loginAdmin();

        $id = $this->postJson('/api/hrms/shifts', [
            'name' => 'Late', 'code' => 'late', 'start_time' => '21:00', 'end_time' => '05:00', 'break_minutes' => 30,
        ])->assertCreated()->assertJsonPath('shift.is_night', true)->json('shift.id');

        $this->assertTrue(HrmsAuditLog::query()->where('action', 'shift.created')->where('subject_id', $id)->exists());

        $this->postJson('/api/hrms/shifts', ['name' => 'Dup', 'code' => 'late', 'start_time' => '09:00', 'end_time' => '17:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_a_split_shift_derives_its_span_and_break(): void
    {
        $this->loginAdmin();

        $this->postJson('/api/hrms/shifts', [
            'name' => 'Split', 'code' => 'split',
            'segments' => [['start' => '17:00', 'end' => '21:00'], ['start' => '09:00', 'end' => '13:00']],
        ])->assertCreated()
            ->assertJsonPath('shift.start_time', '09:00')
            ->assertJsonPath('shift.end_time', '21:00')
            ->assertJsonPath('shift.break_minutes', 240)
            ->assertJsonPath('shift.is_split', true);

        $this->postJson('/api/hrms/shifts', [
            'name' => 'Bad', 'code' => 'bad',
            'segments' => [['start' => '09:00', 'end' => '13:00'], ['start' => '12:00', 'end' => '15:00']],
        ])->assertUnprocessable()->assertJsonValidationErrors('segments');
    }

    public function test_starters_and_shifts_in_use_refuse_deletion(): void
    {
        $this->loginAdmin();

        $general = AttendanceShift::query()->where('code', 'general')->firstOrFail();
        $this->deleteJson("/api/hrms/shifts/{$general->id}")->assertUnprocessable()->assertJsonValidationErrors('form');

        $id = $this->postJson('/api/hrms/shifts', ['name' => 'Tmp', 'code' => 'tmp', 'start_time' => '08:00', 'end_time' => '16:00'])
            ->assertCreated()->json('shift.id');
        $employee = $this->makeEmployee('Shifty');
        $this->postJson("/api/hrms/shifts/{$id}/assign", ['employee_ids' => [$employee->id]])->assertOk()->assertJsonPath('count', 1);

        $this->assertSame($id, $employee->fresh()->shift_id);
        $this->deleteJson("/api/hrms/shifts/{$id}")->assertUnprocessable();

        $this->postJson('/api/hrms/shifts/default/clear', ['employee_ids' => [$employee->id]])->assertOk();
        $this->deleteJson("/api/hrms/shifts/{$id}")->assertOk();
    }

    public function test_a_starter_keeps_its_code_on_update(): void
    {
        $this->loginAdmin();

        $general = AttendanceShift::query()->where('code', 'general')->firstOrFail();
        $this->putJson("/api/hrms/shifts/{$general->id}", ['name' => 'Office hours', 'code' => 'changed', 'grace_minutes' => 10])
            ->assertOk()->assertJsonPath('shift.code', 'general')->assertJsonPath('shift.name', 'Office hours');
    }
}
