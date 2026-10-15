<?php

namespace Tests\Feature;

use App\Enums\Hrms\EmployeeStatus;
use App\Jobs\RefreshPlatformAnalyticsJob;
use App\Models\Hrms\Employee\Employee;
use App\Models\Hrms\Payroll\SalaryComponent;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Providers\AppServiceProvider;
use App\Services\Hrms\Compensation\CompensationService;
use App\Services\Hrms\Payroll\PayrollService;
use App\Services\PlatformResourceTotals;
use App\Services\TenantLimits;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * Phase 2 — Database & Performance gate.
 *
 * Pins the shape the phase shipped: the additive index set (including the
 * ones that already existed and are therefore easy to "clean up"), the
 * single-statement board renumber, the payroll structure map, the request
 * memo on tenant limits, the stale-while-revalidate platform fan-out, and
 * the production guard that keeps sessions/cache/jobs off the tenant DB.
 *
 * Query-count assertions are deliberately loose upper bounds: they must
 * fail on the N+1 pattern the phase removed, not on an unrelated extra
 * SELECT.
 */
class DBPerformanceTest extends TestCase
{
    use IsolatesDatabase;

    // --- indexes -------------------------------------------------------------

    public function test_the_phase2_index_set_is_in_place(): void
    {
        $this->assertIndex('tasks', ['status_id', 'position']);
        $this->assertIndex('notifications', ['user_id', 'read_at', 'created_at']);
        $this->assertIndex('approvals', ['approvable_type', 'approvable_id']);
        $this->assertIndex('offboarding_case_tasks', ['expense_claim_id']);
        $this->assertIndex('payslip_adjustments', ['reference_id']);
        $this->assertIndex('leave_adjustments', ['reference_id']);

        // DB-level idempotency guard behind the application-level upsert.
        $this->assertIndex('attendance_days', ['employee_id', 'work_date'], unique: true);
    }

    public function test_trigram_indexes_stay_opt_in(): void
    {
        $names = array_column(Schema::getIndexes('tasks'), 'name');

        $this->assertEmpty(
            array_filter($names, fn (string $name): bool => str_contains($name, 'trgm')),
            'pg_trgm indexes are ENABLE_TRGM-gated (sqlite has no trigram operators).',
        );

        $this->assertFalse((bool) config('tenancy.tenant.enable_trgm'));
    }

    // --- tenant limits memo --------------------------------------------------

    public function test_tenant_limits_merge_once_until_the_memo_is_flushed(): void
    {
        $plan = SubscriptionPlan::where('slug', 'starter')->firstOrFail();
        Subscription::create([
            'tenant_id' => $this->acme()->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $limits = app(TenantLimits::class);
        TenantLimits::resetMemo();

        $this->assertSame(5, $limits->effective($this->acme())['users']);

        // The plan row moves underneath a memoized request…
        $plan->update(['limits' => array_merge($plan->limits, ['users' => 42])]);

        $this->assertSame(
            5,
            $limits->effective($this->acme()->fresh())['users'],
            'effective() must not re-read the plan inside one request.',
        );

        // …and the explicit flush (test setup / teardown) un-sticks it.
        TenantLimits::resetMemo();

        $this->assertSame(42, $limits->effective($this->acme()->fresh())['users']);
    }

    // --- board renumbering ---------------------------------------------------

    public function test_a_same_column_move_rewrites_positions_once(): void
    {
        $project = $this->projectWithTasks(6);
        $todo = $project->statuses()->where('is_default', true)->first();
        $tasks = $project->tasks()->orderBy('position')->get();
        $this->loginAs('admin@flowsync.test');

        DB::enableQueryLog();
        $this->postJson("/api/projects/{$project->id}/tasks/{$tasks[5]->id}/move", [
            'status_id' => $todo->id,
            'index' => 0,
        ])->assertOk();
        $writes = $this->positionWrites();
        DB::disableQueryLog();

        $this->assertCount(1, $writes, 'A renumber must be one CASE statement, not one UPDATE per task.');

        $order = $project->tasks()->where('status_id', $todo->id)->orderBy('position')->pluck('id')->all();
        $this->assertSame(
            [$tasks[5]->id, $tasks[0]->id, $tasks[1]->id, $tasks[2]->id, $tasks[3]->id, $tasks[4]->id],
            $order,
        );
    }

    public function test_a_cross_column_move_rewrites_both_columns_once_each(): void
    {
        $project = $this->projectWithTasks(4);
        $done = $project->statuses()->where('slug', 'done')->firstOrFail();
        $todo = $project->statuses()->where('is_default', true)->firstOrFail();
        $tasks = $project->tasks()->orderBy('position')->get();
        $this->loginAs('admin@flowsync.test');

        DB::enableQueryLog();
        $this->postJson("/api/projects/{$project->id}/tasks/{$tasks[0]->id}/move", [
            'status_id' => $done->id,
        ])->assertOk();
        $writes = $this->positionWrites();
        DB::disableQueryLog();

        $this->assertCount(2, $writes, 'Source and destination columns are one statement each.');

        // tasks.position is decimal(10,3), so sqlite hands back floats.
        $this->assertSame(
            [1, 2, 3],
            array_map('intval', $project->tasks()->where('status_id', $todo->id)->orderBy('position')->pluck('position')->all()),
        );
    }

    // --- payroll N+1 ---------------------------------------------------------

    public function test_payroll_calculation_stops_requerying_assignments_per_employee(): void
    {
        $structure = app(CompensationService::class)->createStructure(
            ['name' => 'Perf Shell', 'currency' => 'INR', 'effective_from' => '2026-04-01'],
            [['component_id' => SalaryComponent::query()->where('code', 'basic')->firstOrFail()->id, 'value' => 25000, 'sequence' => 10]],
        );

        for ($i = 1; $i <= 6; $i++) {
            $employee = Employee::create([
                'employee_code' => 'EMP-PERF-'.$i,
                'name' => "Perf {$i}",
                'status' => EmployeeStatus::Active,
            ]);

            app(CompensationService::class)->assign($employee, $structure, '600000', '2026-04-01');
        }

        $run = app(PayrollService::class)->openRun([
            'period_year' => 2026,
            'period_month' => 8,
            'pay_period_start' => '2026-08-01',
            'pay_period_end' => '2026-08-31',
            'pay_date' => '2026-09-05',
        ]);

        DB::enableQueryLog();
        $result = app(PayrollService::class)->calculate($run);
        $assignments = $this->queriesContaining('employee_salary_structures');
        DB::disableQueryLog();

        $this->assertSame(['calculated' => 6, 'skipped' => []], $result);
        $this->assertGreaterThan(0, count($assignments));
        $this->assertLessThanOrEqual(
            2,
            count($assignments),
            'Assignments are one map query per 100-employee chunk — 6 employees produced '.count($assignments).' (one-per-employee is the N+1 this phase removed).',
        );
    }

    // --- platform analytics fan-out -----------------------------------------

    public function test_platform_totals_compute_cold_then_serve_the_cached_payload(): void
    {
        $totals = app(PlatformResourceTotals::class);

        Cache::flush();
        $rows = $totals->get();

        $this->assertNotEmpty($rows);
        $this->assertNotNull(Cache::get(PlatformResourceTotals::CACHE_KEY));

        Cache::put(PlatformResourceTotals::CACHE_KEY, [
            'at' => now()->getTimestamp(),
            'rows' => [['tenant' => ['id' => 0, 'name' => 'Sentinel', 'slug' => 'sentinel', 'status' => 'active'], 'users' => 0, 'workspaces' => 0, 'projects' => 0, 'tasks' => 0]],
        ], PlatformResourceTotals::TTL_SECONDS);

        $this->assertSame('Sentinel', $totals->get()->first()['tenant']['name'], 'A warm payload must be served, not recomputed.');
    }

    public function test_a_stale_payload_is_served_while_one_refresh_is_queued(): void
    {
        Queue::fake();

        $totals = app(PlatformResourceTotals::class);
        $sentinel = ['tenant' => ['id' => 0, 'name' => 'Stale', 'slug' => 'stale', 'status' => 'active'], 'users' => 7, 'workspaces' => 0, 'projects' => 0, 'tasks' => 0];

        Cache::put(PlatformResourceTotals::CACHE_KEY, [
            'at' => now()->subSeconds(PlatformResourceTotals::FRESH_SECONDS + 5)->getTimestamp(),
            'rows' => [$sentinel],
        ], PlatformResourceTotals::TTL_SECONDS);

        $this->assertSame(7, $totals->get()->first()['users']);
        $totals->get();

        Queue::assertPushed(RefreshPlatformAnalyticsJob::class, 1);
    }

    public function test_a_fresh_payload_never_queues_a_refresh(): void
    {
        Queue::fake();

        $totals = app(PlatformResourceTotals::class);

        Cache::put(PlatformResourceTotals::CACHE_KEY, [
            'at' => now()->getTimestamp(),
            'rows' => [['tenant' => ['id' => 1, 'name' => 'Fresh', 'slug' => 'fresh', 'status' => 'active'], 'users' => 1, 'workspaces' => 0, 'projects' => 0, 'tasks' => 0]],
        ], PlatformResourceTotals::TTL_SECONDS);

        $totals->get();

        Queue::assertNothingPushed();
    }

    // --- infrastructure connection pins -------------------------------------

    public function test_production_boot_refuses_unpinned_database_infrastructure(): void
    {
        $provider = new AppServiceProvider($this->app);
        $method = new \ReflectionMethod($provider, 'assertInfrastructureConnectionsPinned');

        config([
            'session.driver' => 'database',
            'session.connection' => null,
            'cache.default' => 'database',
            'cache.stores.database.connection' => null,
            'queue.default' => 'sync',
        ]);

        $this->withProductionEnvironment(function () use ($method, $provider): void {
            try {
                $method->invoke($provider);
                $this->fail('An unpinned production session connection must refuse to boot.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('session', $exception->getMessage());
            }
        });
    }

    public function test_production_boot_accepts_pinned_and_non_database_infrastructure(): void
    {
        $provider = new AppServiceProvider($this->app);
        $method = new \ReflectionMethod($provider, 'assertInfrastructureConnectionsPinned');
        $this->expectNotToPerformAssertions();

        config([
            'session.driver' => 'database',
            'session.connection' => 'system',
            'cache.default' => 'database',
            'cache.stores.database.connection' => 'system',
            'queue.default' => 'database',
            'queue.connections.database.connection' => 'system',
        ]);

        $this->withProductionEnvironment(fn () => $method->invoke($provider));

        config([
            'session.driver' => 'file',
            'session.connection' => null,
            'cache.default' => 'array',
            'cache.stores.database.connection' => null,
            'queue.default' => 'sync',
        ]);

        $this->withProductionEnvironment(fn () => $method->invoke($provider));
    }

    public function test_the_pin_guard_ignores_non_production_environments(): void
    {
        $provider = new AppServiceProvider($this->app);

        config(['session.driver' => 'database', 'session.connection' => null]);

        (new \ReflectionMethod($provider, 'assertInfrastructureConnectionsPinned'))->invoke($provider);

        $this->assertTrue(true, 'testing boots with an unpinned session connection every day.');
    }

    // --- helpers -------------------------------------------------------------

    private function withProductionEnvironment(callable $callback): void
    {
        $previous = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $callback();
        } finally {
            $this->app['env'] = $previous;
        }
    }

    /**
     * @return list<string>
     */
    private function positionWrites(): array
    {
        return array_values(array_filter(
            array_column(DB::getQueryLog(), 'query'),
            fn (string $query): bool => str_contains(strtolower($query), 'update tasks set position'),
        ));
    }

    /**
     * @return list<string>
     */
    private function queriesContaining(string $needle): array
    {
        return array_values(array_filter(
            array_column(DB::getQueryLog(), 'query'),
            fn (string $query): bool => str_contains($query, $needle),
        ));
    }

    private function assertIndex(string $table, array $columns, bool $unique = false): void
    {
        $match = array_filter(
            Schema::getIndexes($table),
            fn (array $index): bool => ($index['unique'] ?? false) === $unique
                && array_map('strval', $index['columns']) === array_map('strval', $columns),
        );

        $this->assertNotEmpty($match, "Missing index on {$table}(".implode(', ', $columns).($unique ? ') unique' : '').'.');
    }

    private function projectWithTasks(int $count): Project
    {
        $admin = User::where('email', 'admin@flowsync.test')->firstOrFail();

        $workspace = Workspace::create([
            'created_by' => $admin->id,
            'name' => 'Perf',
            'slug' => 'perf-'.uniqid(),
        ]);
        $workspace->members()->attach($admin->id, ['role' => 'owner', 'added_by' => $admin->id]);

        $project = Project::create([
            'workspace_id' => $workspace->id,
            'created_by' => $admin->id,
            'lead_user_id' => $admin->id,
            'name' => 'Perf Board',
            'key' => 'P'.strtoupper(substr(md5(uniqid('', true)), 0, 5)),
        ]);

        $position = 0;
        foreach (config('task_statuses.statuses') as $status) {
            $position++;
            TaskStatus::create([
                'project_id' => $project->id,
                'name' => $status['name'],
                'slug' => $status['slug'],
                'category' => $status['category'],
                'position' => $position,
                'color' => $status['color'] ?? null,
                'is_default' => $status['is_default'] ?? false,
                'is_done' => $status['is_done'] ?? false,
            ]);
        }

        $project->members()->attach($admin->id, [
            'project_role_id' => ProjectRole::where('slug', 'lead')->firstOrFail()->id,
            'added_by' => $admin->id,
        ]);

        $todo = $project->statuses()->where('is_default', true)->firstOrFail();

        for ($i = 1; $i <= $count; $i++) {
            $project->increment('last_task_sequence');

            Task::create([
                'workspace_id' => $workspace->id,
                'project_id' => $project->id,
                'created_by' => $admin->id,
                'key' => $project->key.'-'.$project->last_task_sequence,
                'sequence' => $project->last_task_sequence,
                'title' => 'Task '.$i,
                'status_id' => $todo->id,
                'position' => $i,
            ]);
        }

        return $project;
    }
}
