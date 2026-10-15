<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Models\Hrms\Attendance\AttendanceDay;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Shared\HrmsSetting;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\Workspace;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P20.4 — attendance from voluntarily logged work, opt-in only.
 *
 * Derivation runs nowhere unless the tenant switched it on (work logs are
 * volunteered — a default-on derivation would let a manager manufacture
 * attendance). Where enabled, closed logs sum to a stamped day
 * (`derived:work_logs`, never regularized); real days are never
 * overwritten, open timers and account-less logins never count, and a
 * re-run refreshes only its own rows.
 */
class HrmsDeriveAttendanceTest extends TestCase
{
    use IsolatesDatabase;

    public function test_derivation_is_off_by_default(): void
    {
        $date = $this->logs();
        $this->assertFalse($this->enabled());

        $this->artisan('hrms:derive-attendance', [
            '--tenant' => $this->acme()->id, '--date' => $date, '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame(0, AttendanceDay::query()->count());
    }

    public function test_a_bare_run_reports_without_writing(): void
    {
        $this->enable();
        $date = $this->logs();

        $this->artisan('hrms:derive-attendance', [
            '--tenant' => $this->acme()->id, '--date' => $date,
        ])->assertSuccessful();

        $this->assertSame(0, AttendanceDay::query()->count());
    }

    public function test_enabled_derivation_marks_days_from_closed_logs(): void
    {
        $this->enable();
        $date = $this->logs();

        $this->artisan('hrms:derive-attendance', [
            '--tenant' => $this->acme()->id, '--date' => $date, '--apply' => true,
        ])->assertSuccessful();

        $day = AttendanceDay::query()->firstOrFail();
        $this->assertSame('present', $day->status->value);
        $this->assertSame(450, $day->worked_minutes);
        $this->assertSame("{$date} 09:00:00", $day->first_in_at->toDateTimeString());
        $this->assertSame("{$date} 17:30:00", $day->last_out_at->toDateTimeString());
        $this->assertFalse($day->is_regularized);
        $this->assertSame('derived:work_logs', $day->note);
    }

    public function test_real_days_are_never_overwritten_and_reruns_refresh(): void
    {
        $this->enable();
        $date = $this->logs();
        $employee = Employee::query()->where('employee_code', 'EMP-DERIVE')->firstOrFail();
        $this->connectTenant('acme');

        AttendanceDay::create([
            'employee_id' => $employee->id,
            'work_date' => $date,
            'status' => 'present',
            'worked_minutes' => 60,
            'first_in_at' => "{$date} 10:00:00",
            'note' => 'punched in',
        ]);

        $this->artisan('hrms:derive-attendance', [
            '--tenant' => $this->acme()->id, '--date' => $date, '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame('punched in', AttendanceDay::query()->firstOrFail()->note);
        $this->assertSame(60, AttendanceDay::query()->firstOrFail()->worked_minutes);

        // Its own rows, however, refresh on re-runs as new logs land.
        AttendanceDay::query()->update(['note' => 'derived:work_logs']);
        WorkLog::query()->update(['duration_minutes' => 500]);

        $this->artisan('hrms:derive-attendance', [
            '--tenant' => $this->acme()->id, '--date' => $date, '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame(1000, AttendanceDay::query()->firstOrFail()->worked_minutes);
    }

    // ------------------------------------------------------------ helpers

    /**
     * Two closed logs (180 + 270 minutes), one open timer, one login with
     * no employee record. Returns the log date.
     */
    private function logs(): string
    {
        $this->connectTenant('acme');
        $date = now()->subDay()->toDateString();

        $user = User::create([
            'name' => 'Derive Logger',
            'email' => 'derive.logger@flowsync.test',
            'password' => 'password',
        ]);

        Employee::create([
            'employee_code' => 'EMP-DERIVE',
            'name' => 'Derive Logger',
            'status' => EmployeeStatus::Active,
            'user_id' => $user->id,
        ]);

        $task = $this->task();

        foreach ([['09:00:00', '12:00:00', 180], ['13:00:00', '17:30:00', 270]] as [$in, $out, $minutes]) {
            WorkLog::create([
                'task_id' => $task->id,
                'user_id' => $user->id,
                'started_at' => "{$date} {$in}",
                'ended_at' => "{$date} {$out}",
                'duration_minutes' => $minutes,
            ]);
        }

        WorkLog::create([
            'task_id' => $task->id,
            'user_id' => $user->id,
            'started_at' => "{$date} 18:00:00",
            'ended_at' => null,
            'duration_minutes' => 0,
        ]);

        $stranger = User::create([
            'name' => 'Derive Stranger',
            'email' => 'derive.stranger@flowsync.test',
            'password' => 'password',
        ]);

        WorkLog::create([
            'task_id' => $task->id,
            'user_id' => $stranger->id,
            'started_at' => "{$date} 09:00:00",
            'ended_at' => "{$date} 17:00:00",
            'duration_minutes' => 480,
        ]);

        return $date;
    }

    private function enable(): void
    {
        $this->connectTenant('acme');
        $settings = HrmsSetting::current();

        $settings->update(['attendance' => [...($settings->attendance ?? []), 'auto_derive_from_work_logs' => true]]);
    }

    private function enabled(): bool
    {
        $this->connectTenant('acme');

        return (bool) HrmsSetting::current()->setting('attendance.auto_derive_from_work_logs', false);
    }

    private function task(): Task
    {
        static $sequence = 0;

        $sequence++;
        $this->connectTenant('acme');
        $admin = User::where('email', 'admin@flowsync.test')->firstOrFail();

        $workspace = Workspace::create([
            'created_by' => $admin->id, 'name' => "Derive {$sequence}", 'slug' => "derive-{$sequence}",
        ]);

        $project = Project::create([
            'workspace_id' => $workspace->id,
            'created_by' => $admin->id,
            'lead_user_id' => $admin->id,
            'name' => "Derive {$sequence}",
            'key' => "DV{$sequence}",
        ]);

        foreach (config('task_statuses.statuses') as $status) {
            $project->statuses()->create([
                'name' => $status['name'],
                'slug' => $status['slug'],
                'category' => $status['category'],
                'color' => $status['color'] ?? null,
                'position' => $status['position'] ?? 1,
                'is_default' => $status['is_default'] ?? false,
                'is_done' => $status['is_done'] ?? false,
            ]);
        }

        $project->increment('last_task_sequence');

        return Task::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'created_by' => $admin->id,
            'key' => $project->key.'-'.$project->last_task_sequence,
            'sequence' => $project->last_task_sequence,
            'title' => "Derive task {$sequence}",
            'status_id' => $project->statuses()->where('is_default', true)->first()->id,
            'position' => 1,
        ]);
    }
}
