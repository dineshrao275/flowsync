<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Enums\Hrms\PunchDirection;
use App\Enums\Hrms\PunchSource;
use App\Jobs\Hrms\Attendance\AttendanceRollupJob;
use App\Models\Hrms\Attendance\AttendanceDay;
use App\Models\Hrms\Attendance\AttendancePunch;
use App\Models\Hrms\Employee\Employee;
use App\Services\Hrms\AttendanceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P5.5 — the nightly close that ensures every active employee owns a day row.
 *
 * What is worth protecting here is the part the idempotent `computeDay`
 * cannot see: the command's scope rule (exactly one of --tenant/--all), the
 * strict date that refuses the future, the dry run that writes nothing, and
 * the job carrying its own tenant — a worker that woke up on the central
 * connection must still close the right tenant's day.
 */
class HrmsAttendanceRollupTest extends TestCase
{
    use IsolatesDatabase;

    public function test_the_command_ensures_one_row_per_active_employee(): void
    {
        $day = $this->day();
        $worker = $this->makeEmployee('Rollup Worker', ['status' => EmployeeStatus::Active]);
        $this->punch($worker, 'in', "{$day} 09:00:00");
        $this->punch($worker, 'out', "{$day} 18:00:00");
        $this->makeEmployee('Rollup Idler', ['status' => EmployeeStatus::Active]);
        $this->makeEmployee('Rollup Ghost', ['status' => EmployeeStatus::Exited]);

        $this->artisan('hrms:attendance-rollup', [
            '--tenant' => $this->acme()->id,
            '--date' => $day,
        ])->assertSuccessful();

        $days = AttendanceDay::query()
            ->whereDate('work_date', $day)
            ->orderBy('employee_id')
            ->get();

        // Two rows: the worker's and the idler's. The exited record is not
        // the rollup's business — closing days for people who left rewrites
        // history nobody asked to revisit.
        $this->assertCount(2, $days);
        $this->assertSame('present', $days[0]->status->value);
        $this->assertSame('absent', $days[1]->status->value);
    }

    public function test_a_second_pass_corrects_instead_of_duplicating(): void
    {
        $day = $this->day();
        $worker = $this->makeEmployee('Rerun Worker', ['status' => EmployeeStatus::Active]);

        $options = ['--tenant' => $this->acme()->id, '--date' => $day];

        $this->artisan('hrms:attendance-rollup', $options)->assertSuccessful();

        // Punches arrive after the first pass; the re-run photographs them
        // onto the same row rather than opening a second one.
        $this->punch($worker, 'in', "{$day} 09:00:00");
        $this->punch($worker, 'out', "{$day} 18:00:00");

        $this->artisan('hrms:attendance-rollup', $options)->assertSuccessful();

        $this->assertSame(1, AttendanceDay::query()->whereDate('work_date', $day)->count());
        $this->assertSame('present', AttendanceDay::query()->whereDate('work_date', $day)->firstOrFail()->status->value);
    }

    public function test_a_dry_run_reports_without_writing(): void
    {
        $this->makeEmployee('Dry Runner', ['status' => EmployeeStatus::Active]);

        $this->artisan('hrms:attendance-rollup', [
            '--tenant' => $this->acme()->id,
            '--date' => $this->day(),
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, AttendanceDay::query()->count());
    }

    public function test_the_scope_is_exactly_one_of_tenant_or_all(): void
    {
        $this->artisan('hrms:attendance-rollup', ['--date' => $this->day()])->assertFailed();
        $this->artisan('hrms:attendance-rollup', [
            '--date' => $this->day(),
            '--tenant' => $this->acme()->id,
            '--all' => true,
        ])->assertFailed();
    }

    public function test_the_date_is_strict_and_never_the_future(): void
    {
        $this->artisan('hrms:attendance-rollup', [
            '--tenant' => $this->acme()->id,
            '--date' => 'next Friday',
        ])->assertFailed();

        $this->artisan('hrms:attendance-rollup', [
            '--tenant' => $this->acme()->id,
            '--date' => today()->addDay()->toDateString(),
        ])->assertFailed();

        $this->assertSame(0, AttendanceDay::query()->count());
    }

    public function test_a_tenant_scoped_run_leaves_the_other_tenant_alone(): void
    {
        $this->makeEmployee('Acme Closer', ['status' => EmployeeStatus::Active]);

        $this->connectTenant('globex');
        $this->makeEmployee('Globex Closer', ['status' => EmployeeStatus::Active]);
        $this->connectTenant('acme');

        $this->artisan('hrms:attendance-rollup', [
            '--tenant' => $this->acme()->id,
            '--date' => $this->day(),
        ])->assertSuccessful();

        $this->assertSame(1, AttendanceDay::query()->count());

        $this->connectTenant('globex');
        $this->assertSame(0, AttendanceDay::query()->count());
    }

    public function test_the_job_closes_the_right_tenant_from_the_central_connection(): void
    {
        $day = $this->day();
        $this->makeEmployee('Job Worker', ['status' => EmployeeStatus::Active]);
        $tenantId = $this->acme()->id;

        // The worker woke up on the system DB: no tenant switched, no
        // session, nothing ambient. The central id on the payload is the
        // only tenant the job may touch — the plan's warning as a test.
        DB::setDefaultConnection('iso_system');

        try {
            AttendanceRollupJob::dispatchSync($tenantId, $day);
        } finally {
            $this->connectTenant('acme');
        }

        $this->assertSame(1, AttendanceDay::query()->whereDate('work_date', $day)->count());
    }

    public function test_the_rollup_is_logged_to_the_hrms_channel_with_the_event_name(): void
    {
        $context = [];

        // The TenantHrmsEntitlementTest pattern: the hrms channel resolves
        // to a recording double, every other channel keeps working.
        $channel = \Mockery::mock(LoggerInterface::class);
        $channel->shouldReceive('info')
            ->once()
            ->with('attendance.rollup.completed', \Mockery::capture($context));

        Log::shouldReceive('channel')
            ->with('hrms')
            ->andReturn($channel);
        Log::shouldReceive('channel')
            ->with(\Mockery::not('hrms'))
            ->andReturnSelf();
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('warning')->andReturnNull();

        $this->makeEmployee('Logged Worker', ['status' => EmployeeStatus::Active]);
        $this->makeEmployee('Logged Idler', ['status' => EmployeeStatus::Active]);

        app(AttendanceService::class)->rollup(Carbon::parse($this->day()));

        $this->assertSame($this->acme()->id, $context['tenant_id']);
        $this->assertSame(2, $context['employees']);
        $this->assertSame(2, $context['ensured']);
    }

    // ------------------------------------------------------------ helpers

    private function day(): string
    {
        // Relative, never hardcoded: a fixed literal rots into the future
        // and the command rightly refuses to close a date nobody has lived.
        return today()->subDays(2)->toDateString();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEmployee(string $name, array $overrides = []): Employee
    {
        static $sequence = 0;

        $sequence++;

        return Employee::create([
            'employee_code' => 'EMP-ROL-'.$sequence,
            'name' => $name,
            'status' => EmployeeStatus::Active,
            ...$overrides,
        ]);
    }

    private function punch(Employee $employee, string $direction, string $at): void
    {
        AttendancePunch::create([
            'employee_id' => $employee->id,
            'punch_at' => $at,
            'direction' => PunchDirection::from($direction)->value,
            'source' => PunchSource::Web->value,
            'is_out_of_range' => false,
        ]);
    }
}
