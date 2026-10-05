<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Enums\Hrms\GoalMetricType;
use App\Enums\Hrms\GoalProgressSource;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Performance\GoalTaskLink;
use App\Models\Hrms\Performance\PerformanceCycle;
use App\Models\Hrms\Performance\PerformanceGoal;
use App\Models\Hrms\TaskLink\TaskLink;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\Workspace;
use App\Services\Hrms\PerformanceService;
use App\Services\TaskService;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P12.2 — task-derived evidence, never scores.
 *
 * Completion counts, logged minutes and overdue rows photograph from the
 * work record with the board's own membership scoping; manual goals and
 * login-less records are left alone; completing a linked task refreshes
 * its goals on the event; and the sweep command reports honestly in dry
 * runs. Statuses and ratings are never written by any of it.
 */
class HrmsPerformanceEvidenceTest extends TestCase
{
    use IsolatesDatabase;

    public function test_completion_counts_photograph_with_membership_scoping(): void
    {
        [$user, $project] = $this->memberSetup();
        $goal = $this->goal($user, GoalMetricType::TaskCompletion, '2');

        app(PerformanceService::class)->refreshGoalEvidence($goal);

        $goal->refresh();

        $this->assertSame('100.00', (string) $goal->progress_percent);
        $this->assertSame('auto', $goal->progress_source->value);
        $this->assertSame(2, $goal->progress_evidence['completed']);
        $this->assertSame(3, $goal->progress_evidence['created']);
        $this->assertSame(1, $goal->progress_evidence['overdue']);
        $this->assertSame('active', $goal->status->value);
    }

    public function test_explicit_links_join_the_pool_with_auditable_ids(): void
    {
        [$user, $project] = $this->memberSetup();
        $goal = $this->goal($user, GoalMetricType::TaskCompletion, '10');
        $employee = Employee::where('user_id', $user->id)->firstOrFail();
        $done = $project->statuses()->where('is_done', true)->firstOrFail();

        // Someone else's completed task inside the member project: linked
        // work counts once the owner names it as evidence.
        $borrowed = Task::query()
            ->where('project_id', $project->id)
            ->where('assignee_id', '!=', $user->id)
            ->whereNotNull('completed_at')
            ->firstOrFail();

        TaskLink::create(['employee_id' => $employee->id, 'task_id' => $borrowed->id, 'kind' => 'goal']);

        // A completed task in a project the owner never joined: linked but
        // invisible, so priced out of the pool and off the id lists.
        $hidden = $this->hiddenTask();

        TaskLink::create(['employee_id' => $employee->id, 'task_id' => $hidden->id, 'kind' => 'goal']);

        app(PerformanceService::class)->refreshGoalEvidence($goal);

        $evidence = $goal->refresh()->progress_evidence;

        $this->assertSame(3, $evidence['completed']);
        $this->assertSame('30.00', (string) $goal->progress_percent);

        $assigned = Task::query()->where('assignee_id', $user->id)
            ->whereNotNull('completed_at')
            ->whereDate('completed_at', '>=', '2026-01-01')
            ->whereDate('completed_at', '<=', '2026-12-31')
            ->pluck('id')->all();

        $this->assertEqualsCanonicalizing([...$assigned, $borrowed->id], $evidence['task_ids']);
        $this->assertSame([$borrowed->id], $evidence['linked_task_ids']);
    }

    public function test_logged_minutes_photograph_with_days(): void
    {
        [$user] = $this->memberSetup();
        $goal = $this->goal($user, GoalMetricType::WorklogHours, '10');

        $task = Task::query()->where('assignee_id', $user->id)->firstOrFail();

        WorkLog::create([
            'task_id' => $task->id, 'user_id' => $user->id,
            'started_at' => '2026-08-10 09:00:00', 'duration_minutes' => 120,
        ]);
        WorkLog::create([
            'task_id' => $task->id, 'user_id' => $user->id,
            'started_at' => '2026-08-11 09:00:00', 'duration_minutes' => 60,
        ]);

        app(PerformanceService::class)->refreshGoalEvidence($goal);

        $goal->refresh();

        $this->assertSame('30.00', (string) $goal->progress_percent);
        $this->assertSame(180, $goal->progress_evidence['minutes']);
        $this->assertSame(3, $goal->progress_evidence['hours']);
        $this->assertSame(2, $goal->progress_evidence['days_logged']);
    }

    public function test_manual_goals_and_login_less_records_are_untouched(): void
    {
        [$user] = $this->memberSetup();

        $manual = $this->goal($user, GoalMetricType::Manual, null, '42.50');
        app(PerformanceService::class)->refreshGoalEvidence($manual);

        $this->assertSame('42.50', (string) $manual->refresh()->progress_percent);
        $this->assertSame('manual', $manual->progress_source->value);
        $this->assertNull($manual->progress_evidence);

        $ghost = Employee::create([
            'employee_code' => 'EMP-PER-GHOST', 'name' => 'Ghost', 'status' => EmployeeStatus::Active,
        ]);
        $cycle = PerformanceCycle::query()->firstOrFail();
        $orphan = PerformanceGoal::create([
            'cycle_id' => $cycle->id, 'employee_id' => $ghost->id,
            'title' => 'Orphan.', 'metric_type' => GoalMetricType::TaskCompletion,
        ]);

        app(PerformanceService::class)->refreshGoalEvidence($orphan);

        $this->assertSame('0.00', (string) $orphan->refresh()->progress_percent);
        $this->assertNull($orphan->progress_evidence);
    }

    public function test_completing_a_linked_task_refreshes_its_goals(): void
    {
        [$user, $project] = $this->memberSetup();
        $goal = $this->goal($user, GoalMetricType::TaskCompletion, '10');

        $open = Task::query()->where('assignee_id', $user->id)->whereNull('completed_at')->firstOrFail();
        GoalTaskLink::create(['goal_id' => $goal->id, 'task_id' => $open->id]);

        $done = $project->statuses()->where('is_done', true)->firstOrFail();
        app(TaskService::class)->move($open, $done->id, null);

        $this->assertSame(3, $goal->refresh()->progress_evidence['completed']);
    }

    public function test_the_sweep_command_names_its_scope(): void
    {
        [$user] = $this->memberSetup();
        $this->goal($user, GoalMetricType::TaskCompletion, '2');
        $cycle = PerformanceCycle::query()->firstOrFail();

        $this->artisan('hrms:performance-evidence', [
            '--cycle' => $cycle->id, '--tenant' => $this->acme()->id, '--dry-run' => true,
        ])->assertSuccessful();

        $this->artisan('hrms:performance-evidence', [
            '--cycle' => $cycle->id, '--tenant' => $this->acme()->id,
        ])->assertSuccessful();

        // Neither tenant scope is a footgun.
        $this->artisan('hrms:performance-evidence', ['--cycle' => $cycle->id])->assertFailed();
    }

    // ------------------------------------------------------------ helpers

    /**
     * An employee with a login, a project they belong to, and five tasks:
     * two completed in-window, one open and overdue, one completed last
     * year, and one completed in-window but assigned to someone else.
     *
     * @return array{User, Project}
     */
    private function memberSetup(): array
    {
        $this->connectTenant('acme');

        $user = User::create([
            'name' => 'Evidence User', 'email' => 'evidence.user@flowsync.test', 'password' => 'password',
        ]);
        $other = User::create([
            'name' => 'Evidence Other', 'email' => 'evidence.other@flowsync.test', 'password' => 'password',
        ]);

        $workspace = Workspace::create(['created_by' => $user->id, 'name' => 'Evidence', 'slug' => 'evidence']);
        $workspace->members()->attach($user->id, ['role' => 'member', 'added_by' => $user->id]);

        $project = Project::create([
            'workspace_id' => $workspace->id, 'created_by' => $user->id,
            'lead_user_id' => $user->id, 'name' => 'Evidence', 'key' => 'EVD',
        ]);
        $todo = TaskStatus::create([
            'project_id' => $project->id, 'name' => 'Todo', 'slug' => 'todo',
            'category' => 'todo', 'position' => 10, 'is_default' => true, 'is_done' => false,
        ]);
        TaskStatus::create([
            'project_id' => $project->id, 'name' => 'Done', 'slug' => 'done',
            'category' => 'done', 'position' => 20, 'is_default' => false, 'is_done' => true,
        ]);

        $role = ProjectRole::where('slug', 'developer')->firstOrFail();
        $project->members()->attach($user->id, ['project_role_id' => $role->id, 'added_by' => $user->id]);

        $sequence = 0;
        $task = function (?int $assignee, ?string $completed, string $created, ?string $due = null) use ($project, $todo, $user, &$sequence): Task {
            $sequence++;

            // created_at rides the query builder: it is not fillable, and a
            // build date of "now" would silently join the window under test.
            $record = Task::create([
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->id,
                'created_by' => $user->id,
                'key' => "EVD-{$sequence}",
                'sequence' => $sequence,
                'title' => "Evidence task {$sequence}.",
                'status_id' => $completed === null ? $todo->id : $project->statuses()->where('is_done', true)->firstOrFail()->id,
                'position' => $sequence,
                'assignee_id' => $assignee,
                'completed_at' => $completed,
                'due_date' => $due,
            ]);

            Task::query()->whereKey($record->id)->update(['created_at' => $created]);

            return $record->refresh();
        };

        $task($user->id, '2026-05-01', '2026-02-01');
        $task($user->id, '2026-06-01', '2026-02-15');
        $task($user->id, null, '2026-03-01', '2026-01-01');
        $task($user->id, '2025-12-01', '2025-11-01');
        $task($other->id, '2026-07-01', '2026-04-01');

        return [$user, $project->refresh()];
    }

    /**
     * A completed in-window task in a project the member never joined:
     * linkable, but invisible to them, so evidence must price it out.
     */
    private function hiddenTask(): Task
    {
        $this->connectTenant('acme');
        $owner = User::where('email', 'admin@flowsync.test')->firstOrFail();

        $workspace = Workspace::create(['created_by' => $owner->id, 'name' => 'Hidden', 'slug' => 'hidden-evidence']);
        $project = Project::create([
            'workspace_id' => $workspace->id, 'created_by' => $owner->id,
            'lead_user_id' => $owner->id, 'name' => 'Hidden', 'key' => 'HID',
        ]);

        $todo = TaskStatus::create([
            'project_id' => $project->id, 'name' => 'Todo', 'slug' => 'todo',
            'category' => 'todo', 'position' => 10, 'is_default' => true, 'is_done' => false,
        ]);
        $done = TaskStatus::create([
            'project_id' => $project->id, 'name' => 'Done', 'slug' => 'done',
            'category' => 'done', 'position' => 20, 'is_default' => false, 'is_done' => true,
        ]);

        return Task::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'created_by' => $owner->id,
            'key' => 'HID-1',
            'sequence' => 1,
            'title' => 'Hidden evidence task.',
            'status_id' => $done->id,
            'position' => 1,
            'completed_at' => '2026-08-01',
        ]);
    }

    private function goal(User $user, GoalMetricType $metric, ?string $target, string $progress = '0.00'): PerformanceGoal
    {
        $this->connectTenant('acme');

        $cycle = PerformanceCycle::firstOrCreate(
            ['slug' => 'h1-2026'],
            ['name' => 'H1 2026', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31'],
        );

        $employee = Employee::create([
            'employee_code' => 'EMP-PER-'.$user->id,
            'name' => "Evidence {$user->id}",
            'status' => EmployeeStatus::Active,
            'user_id' => $user->id,
        ]);

        return PerformanceGoal::create([
            'cycle_id' => $cycle->id,
            'employee_id' => $employee->id,
            'title' => 'Evidence goal.',
            'metric_type' => $metric,
            'target_value' => $target,
            'status' => 'active',
            'progress_percent' => $progress,
            'progress_source' => GoalProgressSource::Manual,
        ]);
    }
}
