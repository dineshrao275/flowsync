<?php

namespace Tests\Feature;

use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Expense\ExpenseClaim;
use App\Models\Hrms\Leave\LeaveRequest;
use App\Models\Hrms\Leave\LeaveType;
use App\Models\Hrms\Payroll\PayrollRun;
use App\Models\Hrms\Performance\PerformanceCycle;
use App\Models\Priority;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\Tenant;
use App\Models\TenantUserRouting;
use App\Models\User;
use App\Models\Workspace;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class FactoryParityTest extends TestCase
{
    use IsolatesDatabase;

    public function test_central_model_factories_create_valid_records(): void
    {
        // 1. Tenant factory
        $tenant = Tenant::factory()->create();
        $this->assertNotNull($tenant->id);
        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->status);

        $trialTenant = Tenant::factory()->trialing()->create();
        $this->assertSame(Tenant::STATUS_TRIAL, $trialTenant->status);
        $this->assertNotNull($trialTenant->trial_ends_at);

        // 2. TenantUserRouting factory
        $routing = TenantUserRouting::factory()->create(['tenant_id' => $tenant->id]);
        $this->assertNotNull($routing->id);
        $this->assertSame($tenant->id, $routing->tenant_id);

        // 3. SubscriptionPlan factory
        $plan = SubscriptionPlan::factory()->create();
        $this->assertNotNull($plan->id);
        $this->assertTrue($plan->is_active);

        // 4. Subscription factory
        $sub = Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
        ]);
        $this->assertNotNull($sub->id);
        $this->assertSame(Subscription::STATUS_ACTIVE, $sub->status);

        $canceledSub = Subscription::factory()->canceled()->create([
            'tenant_id' => $trialTenant->id,
            'plan_id' => $plan->id,
        ]);
        $this->assertSame(Subscription::STATUS_CANCELED, $canceledSub->status);
        $this->assertFalse($canceledSub->auto_renew);
    }

    public function test_tenant_domain_factories_maintain_fk_integrity(): void
    {
        $this->loginAs('admin@flowsync.test');
        $admin = User::where('email', 'admin@flowsync.test')->firstOrFail();

        // 5. Workspace factory
        $workspace = Workspace::factory()->create(['created_by' => $admin->id]);
        $this->assertNotNull($workspace->id);
        $this->assertSame($admin->id, $workspace->created_by);

        // 6. Project factory
        $project = Project::factory()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $admin->id,
            'lead_user_id' => $admin->id,
        ]);
        $this->assertNotNull($project->id);
        $this->assertSame($workspace->id, $project->workspace_id);

        // 7. Task factory
        $status = $project->statuses()->first() ?? TaskStatus::create([
            'project_id' => $project->id,
            'name' => 'Todo',
            'slug' => 'todo',
            'category' => 'todo',
            'position' => 1,
        ]);
        $priority = Priority::first();

        $task = Task::factory()->create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'created_by' => $admin->id,
            'status_id' => $status->id,
            'priority_id' => $priority?->id ?? 1,
        ]);
        $this->assertNotNull($task->id);
        $this->assertSame($project->id, $task->project_id);
    }

    public function test_hrms_domain_factories_create_valid_records(): void
    {
        $this->loginAs('admin@flowsync.test');
        $admin = User::where('email', 'admin@flowsync.test')->firstOrFail();

        // 8. Employee factory
        $employee = Employee::factory()->create(['user_id' => $admin->id]);
        $this->assertNotNull($employee->id);
        $this->assertSame($admin->id, $employee->user_id);
        $this->assertStringStartsWith('EMP-', $employee->employee_code);

        // 9. LeaveRequest factory
        $leaveType = LeaveType::first() ?? LeaveType::create([
            'name' => 'Paid Time Off',
            'code' => 'PTO',
            'days_per_year' => 20,
        ]);

        $leave = LeaveRequest::factory()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
        ]);
        $this->assertNotNull($leave->id);
        $this->assertSame($employee->id, $leave->employee_id);

        // 10. PayrollRun factory
        $payroll = PayrollRun::factory()->create([
            'initiated_by_user_id' => $admin->id,
        ]);
        $this->assertNotNull($payroll->id);
        $this->assertIsArray($payroll->totals);

        // 11. ExpenseClaim factory
        $expense = ExpenseClaim::factory()->create([
            'employee_id' => $employee->id,
        ]);
        $this->assertNotNull($expense->id);
        $this->assertSame($employee->id, $expense->employee_id);

        // 12. PerformanceCycle factory
        $cycle = PerformanceCycle::factory()->create([
            'created_by' => $admin->id,
        ]);
        $this->assertNotNull($cycle->id);
        $this->assertSame($admin->id, $cycle->created_by);
    }
}
