<?php

namespace Tests\Feature;

use App\Models\Hrms\Attendance\AttendanceDay;
use App\Models\Hrms\Attendance\AttendancePunch;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Org\Location;
use App\Models\Hrms\Shared\HrmsSetting;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P5.15 — out-of-range punches are flagged (default) or blocked (tenant
 * setting), the switch-off toggle is honoured, and break punches measure the
 * day's real break instead of the shift's standard one.
 */
class HrmsPunchPolicyTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setAcmeModules(['hrms.core', 'hrms.attendance', 'hrms.attendance.remote']);
        $this->loginAdmin();

        $fence = Location::create([
            'name' => 'HQ', 'slug' => 'hq-fence', 'geo_lat' => 12.9716, 'geo_lng' => 77.5946, 'geo_radius_m' => 200, 'is_geo_fenced' => true,
        ]);
        $this->employee = $this->makeEmployee('Fenced', [
            'user_id' => User::where('email', 'admin@flowsync.test')->value('id'),
            'location_id' => $fence->id,
        ]);
    }

    private function setAction(string $action, array $extra = []): void
    {
        $settings = HrmsSetting::current();
        $settings->remote_clock_in = array_merge($settings->remote_clock_in ?? [], ['out_of_range_action' => $action], $extra);
        $settings->save();
    }

    private function punch(array $body): TestResponse
    {
        return $this->postJson('/api/hrms/attendance/punch', $body + ['direction' => 'in', 'punch_at' => '2026-11-02 09:00:00']);
    }

    public function test_the_default_flags_an_out_of_range_punch_and_keeps_it(): void
    {
        $this->punch(['lat' => 13.5, 'lng' => 77.5946])->assertCreated()->assertJsonPath('punch.is_out_of_range', true);

        $this->assertSame(1, AttendancePunch::query()->where('employee_id', $this->employee->id)->count());
    }

    public function test_block_mode_refuses_an_out_of_range_punch_and_a_missing_position(): void
    {
        $this->setAction('block');

        $this->punch(['lat' => 13.5, 'lng' => 77.5946])->assertUnprocessable()->assertJsonValidationErrors('location');
        $this->punch([])->assertUnprocessable()->assertJsonValidationErrors('location');
        $this->assertSame(0, AttendancePunch::query()->count());

        $this->punch(['lat' => 12.9716, 'lng' => 77.5946])->assertCreated()->assertJsonPath('punch.is_out_of_range', false);
    }

    public function test_flag_mode_with_require_geofence_flags_a_missing_position(): void
    {
        $this->setAction('flag', ['require_geofence' => true]);

        $this->punch([])->assertCreated()->assertJsonPath('punch.is_out_of_range', true);
    }

    public function test_switching_remote_clock_in_off_refuses_the_endpoint(): void
    {
        $this->setAction('flag', ['enabled' => false]);

        $this->punch(['lat' => 12.9716, 'lng' => 77.5946])->assertForbidden();
    }

    public function test_break_punches_measure_the_real_break(): void
    {
        $in = ['lat' => 12.9716, 'lng' => 77.5946];
        $this->punch($in)->assertCreated();
        $this->punch($in + ['direction' => 'out', 'kind' => 'break', 'punch_at' => '2026-11-02 12:00:00'])->assertCreated()->assertJsonPath('punch.kind', 'break');
        $this->punch($in + ['direction' => 'in', 'kind' => 'break', 'punch_at' => '2026-11-02 12:45:00'])->assertCreated();
        $this->punch($in + ['direction' => 'out', 'punch_at' => '2026-11-02 18:00:00'])->assertCreated();

        $day = AttendanceDay::query()->where('employee_id', $this->employee->id)->firstOrFail();

        $this->assertSame(495, $day->worked_minutes);
        $this->assertSame(45, $day->break_minutes);
        $this->assertSame('09:00', $day->first_in_at->format('H:i'));
        $this->assertSame('18:00', $day->last_out_at->format('H:i'));
    }

    public function test_an_unknown_punch_kind_is_a_validation_error(): void
    {
        $this->punch(['kind' => 'nap'])->assertUnprocessable()->assertJsonValidationErrors('kind');
    }
}
