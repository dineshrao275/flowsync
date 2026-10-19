<?php

namespace Tests\Feature;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Leave\LeaveRequest;
use App\Models\Hrms\Leave\LeaveRequestDay;
use App\Models\Hrms\Leave\LeaveType;
use App\Models\Task;
use App\Models\User;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F4.4 — workload vs capacity per member, with approved leave reducing capacity. */
class WorkloadTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    private int $project;

    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setAcmeModules(['sprints', 'time_tracking', 'hrms.core', 'hrms.leave']);
        $this->loginAdmin();
        $this->adminId = (int) User::where('email', 'admin@flowsync.test')->value('id');

        $workspace = $this->postJson('/api/workspaces', ['name' => 'WL WS'])->assertCreated()->json('workspace.id');
        $this->project = $this->postJson("/api/workspaces/{$workspace}/projects", ['name' => 'WL Project'])->assertCreated()->json('project.id');
    }

    private function task(string $title, ?int $estimate, ?string $due, bool $assigned = true): void
    {
        $this->postJson("/api/projects/{$this->project}/tasks", array_filter([
            'title' => $title,
            'assignee_id' => $assigned ? $this->adminId : null,
            'estimate_minutes' => $estimate,
            'due_date' => $due,
        ], fn ($v) => $v !== null))->assertCreated();
    }

    private function member(array $workload): array
    {
        return collect($workload['members'])->firstWhere('user.id', $this->adminId);
    }

    private function week(): array
    {
        // A fixed Monday so the arithmetic below is exact.
        return ['from' => '2026-10-05', 'to' => '2026-10-11'];
    }

    public function test_capacity_planned_work_and_overallocation(): void
    {
        $this->task('Big one', 1800, '2026-10-07');
        $this->task('Another', 900, '2026-10-09');
        $this->task('No estimate', null, '2026-10-08');
        $this->task('Later', 600, '2026-11-20');
        $this->task('Floating', 300, null);

        $w = $this->getJson("/api/projects/{$this->project}/workload?".http_build_query($this->week()))->assertOk()->json('workload');
        $me = $this->member($w);

        $this->assertSame(5, $w['working_days']);
        $this->assertSame(2400, $me['capacity_minutes']); // 5 days x 8h
        $this->assertSame(2700, $me['planned_minutes']);   // only tasks due in the range
        $this->assertTrue($me['overallocated']);
        $this->assertSame(1.125, $me['utilization']);
        $this->assertSame(3, $me['open_tasks']);
        $this->assertSame(1, $me['unestimated_tasks']);
        $this->assertSame(1, $me['unscheduled_tasks']);
    }

    public function test_completed_tasks_do_not_count_as_planned_work(): void
    {
        $this->task('Open', 600, '2026-10-06');
        $this->postJson("/api/projects/{$this->project}/tasks", ['title' => 'Done already', 'assignee_id' => $this->adminId, 'estimate_minutes' => 6000, 'due_date' => '2026-10-06'])->assertCreated();
        Task::where('title', 'Done already')->update(['completed_at' => now()]);

        $me = $this->member($this->getJson("/api/projects/{$this->project}/workload?".http_build_query($this->week()))->json('workload'));

        $this->assertSame(600, $me['planned_minutes']);
        $this->assertFalse($me['overallocated']);
    }

    public function test_approved_leave_reduces_capacity_and_other_statuses_do_not(): void
    {
        $employee = Employee::create(['employee_code' => 'EMP-WL', 'name' => 'Admin Emp', 'status' => 'active', 'user_id' => $this->adminId]);
        $type = LeaveType::query()->where('code', 'annual')->firstOrFail();

        $approved = LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'from_date' => '2026-10-06', 'to_date' => '2026-10-07', 'total_days' => 2, 'status' => 'approved', 'reason' => 'Holiday']);
        foreach (['2026-10-06', '2026-10-07'] as $date) {
            LeaveRequestDay::create(['leave_request_id' => $approved->id, 'date' => $date, 'is_holiday' => false, 'is_week_off' => false, 'is_half_day' => false]);
        }
        $pending = LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'from_date' => '2026-10-08', 'to_date' => '2026-10-08', 'total_days' => 1, 'status' => 'pending', 'reason' => 'Holiday']);
        LeaveRequestDay::create(['leave_request_id' => $pending->id, 'date' => '2026-10-08', 'is_holiday' => false, 'is_week_off' => false, 'is_half_day' => false]);

        $me = $this->member($this->getJson("/api/projects/{$this->project}/workload?".http_build_query($this->week()))->json('workload'));

        $this->assertEquals(2.0, $me['leave_days']);
        $this->assertSame(1440, $me['capacity_minutes']); // 3 working days left x 8h
    }

    public function test_the_range_is_bounded_and_the_module_gates_it(): void
    {
        $this->getJson("/api/projects/{$this->project}/workload?from=2026-01-05&to=2026-12-31")->assertUnprocessable();

        $this->setAcmeModules(['time_tracking']);
        $this->getJson("/api/projects/{$this->project}/workload")->assertForbidden();
    }
}
