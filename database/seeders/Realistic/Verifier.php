<?php

namespace Database\Seeders\Realistic;

use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantUserRouting;
use App\Services\TenantLimits;
use App\Support\TenantDatabaseManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consistency checks for the realistic demo dataset. Every check returns zero offending rows when the data is right;
 * a failure is reported as "<tenant slug>: <what> (<count>)". Used by `tenants:verify-realistic` and the feature test.
 */
final class Verifier
{
    /** module => tables that must stay empty when the tenant's plans do not include the module */
    private const MODULE_TABLES = [
        'hrms.leave' => ['leave_requests', 'leave_balances'], 'hrms.attendance' => ['attendance_days', 'attendance_punches'],
        'hrms.expenses' => ['expense_claims'], 'hrms.assets' => ['assets', 'asset_assignments'], 'hrms.payroll' => ['payroll_runs', 'payslips'],
        'hrms.compensation' => ['salary_structures', 'employee_salary_structures'], 'hrms.performance' => ['performance_goals', 'performance_cycles'],
        'hrms.talent' => ['employee_skills'], 'hrms.onboarding' => ['onboarding_cases'], 'hrms.offboarding' => ['offboarding_cases'],
        'sprints' => ['sprints'], 'automation' => ['automation_rules', 'automation_runs'], 'time_tracking' => ['work_logs'],
        'timesheets' => ['timesheets'], 'saved_filters' => ['saved_filters'],
    ];

    /** @var list<string> */
    private array $failures = [];

    /** @return list<string> */
    public function failures(): array
    {
        return $this->failures;
    }

    /** Checks that need no tenant database: counts, uniqueness, category/plan/state coverage. */
    public function central(): void
    {
        $tenants = Tenant::withTrashed()->get()->filter(fn (Tenant $t) => isset($t->settings['demo_seed']));
        $expected = array_sum((array) config('demo_seed.tenants'));
        $this->assert($tenants->count() === $expected, "tenant count is {$tenants->count()}, expected {$expected}");
        $this->assert($tenants->pluck('name')->unique()->count() === $tenants->count(), 'tenant names are not unique');
        $this->assert($tenants->pluck('slug')->unique()->count() === $tenants->count(), 'tenant slugs are not unique');
        $this->assert($tenants->every(fn ($t) => (bool) ($t->settings['demo_seed']['complete'] ?? false)), 'some demo tenants are not marked complete');

        foreach ((array) config('demo_seed.tenants') as $category => $n) {
            $this->assert($tenants->filter(fn ($t) => $t->settings['demo_seed']['category'] === $category)->count() === (int) $n, "category {$category} does not have {$n} tenants");
        }

        $states = $tenants->map(fn ($t) => $t->settings['demo_seed']['state'])->unique()->sort()->values()->all();
        $this->assert($states === ['active', 'canceled', 'expired', 'past_due', 'trialing'], 'not every subscription state is present: '.implode(',', $states));
        $statuses = $tenants->pluck('status')->unique()->sort()->values()->all();
        $this->assert(count(array_diff(['active', 'deactivated', 'expired', 'suspended', 'trial'], $statuses)) === 0, 'not every tenant status is present: '.implode(',', $statuses));

        $plans = DB::connection(config('tenancy.system.connection'))->table('subscriptions')->join('subscription_plans', 'subscription_plans.id', '=', 'subscriptions.plan_id')
            ->whereIn('subscriptions.tenant_id', $tenants->pluck('id'))->distinct()->pluck('subscription_plans.slug')->all();
        $this->assert(count(array_diff(SubscriptionPlan::pluck('slug')->all(), $plans)) === 0, 'some plans are not used by any demo tenant');
    }

    public function tenant(Tenant $tenant, TenantDatabaseManager $dbm): void
    {
        $bp = $tenant->settings['demo_seed'];
        $hrms = $bp['category'] !== 'tms';
        $tms = $bp['category'] !== 'hrms';

        $subs = $tenant->subscriptions()->get();
        $this->assert($subs->count() === ($bp['category'] === 'both' ? $subs->count() : 1), "{$tenant->slug}: expected one subscription for a single-product tenant");
        $products = $subs->flatMap(fn ($s) => $s->plan->covers())->unique()->sort()->values()->all();
        $this->assert($products === ($bp['category'] === 'both' ? ['hrms', 'tms'] : [$bp['category']]), "{$tenant->slug}: subscription products ".implode('+', $products).' do not match category');
        $map = ['trialing' => ['trialing', 'trial'], 'active' => ['active', 'active'], 'past_due' => ['past_due', 'suspended'], 'expired' => ['expired', 'expired'], 'canceled' => ['canceled', 'deactivated']];
        [$subStatus, $tenantStatus] = $map[$bp['state']];
        $this->assert($subs->every(fn ($s) => $s->status === $subStatus) && $tenant->status === $tenantStatus, "{$tenant->slug}: lifecycle mismatch (sub ".$subs->pluck('status')->implode(',').", tenant {$tenant->status})");

        $users = 0;
        $dbm->using($tenant, function () use ($tenant, $hrms, $tms, &$users): void {
            $limits = app(TenantLimits::class);
            TenantLimits::resetMemo();
            $end = CarbonImmutable::parse($tenant->updated_at);
            $slug = $tenant->slug;

            $users = (int) DB::table('users')->count();
            $this->assert($users >= (int) config('demo_seed.users.min') && $users <= (int) config('demo_seed.users.max'), "$slug: $users users outside the configured range");
            $routed = TenantUserRouting::where('tenant_id', $tenant->id)->count();
            $this->assert($routed === $users, "$slug: $routed login routes for $users users");
            $this->assert($this->count('users u left join role_user r on r.user_id = u.id where r.user_id is null') === 0, "$slug: users without any role");

            $this->assert(Schema::hasTable('employees') === $hrms, "$slug: HRMS schema presence does not match the subscription");
            $this->assert(Schema::hasTable('tasks') === $tms, "$slug: TMS schema presence does not match the subscription");

            foreach (self::MODULE_TABLES as $module => $tables) {
                if ($limits->hasModule($tenant, $module)) {
                    continue;
                }
                foreach ($tables as $table) {
                    if (Schema::hasTable($table) && DB::table($table)->count() > 0) {
                        $this->failures[] = "$slug: '$table' has rows but the plan does not include '$module'";
                    }
                }
            }

            $hrms && $this->hrms($slug, $users, $end, $limits, $tenant);
            $tms && $this->tms($slug, $end, $tenant);
        });
    }

    private function hrms(string $slug, int $users, CarbonImmutable $end, TenantLimits $limits, Tenant $tenant): void
    {
        $this->assert((int) DB::table('employees')->count() === $users, "$slug: employee count differs from user count");
        $this->assert($this->count('users u left join employees e on e.user_id = u.id where e.id is null') === 0, "$slug: users without an employee record");
        $this->assert($this->count('employees e left join employees m on m.id = e.manager_id where e.manager_id is not null and m.id is null') === 0, "$slug: manager points at a missing employee");

        // reporting chain: no cycles, depth bounded
        $mgr = DB::table('employees')->pluck('manager_id', 'id')->all();
        $loops = 0;
        foreach ($mgr as $id => $m) {
            for ($d = 0, $cur = $m; $cur !== null && $d < 30; $d++) {
                $cur = $mgr[$cur] ?? null;
            }
            $loops += $cur !== null ? 1 : 0;
        }
        $this->assert($loops === 0, "$slug: reporting chain has a cycle ($loops)");
        $this->assert($this->count("employees where status in ('exited','terminated') and exit_date is null") === 0, "$slug: exited employees without exit date");
        $this->assert($this->count('employees where exit_date is not null and exit_date <= joining_date') === 0, "$slug: exit before joining");
        $this->assert($this->count("employees where joining_date > '".$end->toDateString()."'") === 0, "$slug: employees join after the tenant's data ends");

        if (Schema::hasTable('leave_balances') && $limits->hasModule($tenant, 'hrms.leave')) {
            $this->assert($this->count('leave_balances where abs(balance - (opening + accrued + carried_forward + adjusted - availed - encashed - lapsed)) > 0.01') === 0, "$slug: leave balance arithmetic");
            $this->assert($this->count("leave_requests where status = 'approved' and approval_id is null") === 0, "$slug: approved leave without approval record");
            $rows = DB::table('leave_requests')->whereIn('status', ['approved', 'pending'])->orderBy('employee_id')->orderBy('from_date')->get(['employee_id', 'from_date', 'to_date']);
            $overlap = 0;
            $prev = null;
            foreach ($rows as $r) {
                if ($prev && $prev->employee_id === $r->employee_id && substr($r->from_date, 0, 10) <= substr($prev->to_date, 0, 10)) {
                    $overlap++;
                }
                $prev = $r;
            }
            $this->assert($overlap === 0, "$slug: overlapping leave requests ($overlap)");
            $this->assert($this->count('leave_requests r left join employees e on e.id = r.employee_id where r.from_date < e.joining_date') === 0, "$slug: leave before joining");
            if (Schema::hasTable('attendance_days')) {
                $this->assert($this->count("leave_request_days d join leave_requests r on r.id = d.leave_request_id join attendance_days a on a.employee_id = r.employee_id and a.work_date = d.date where r.status = 'approved' and a.status <> 'leave'") === 0, "$slug: approved leave days shown as another attendance status");
            }
        }
        if (Schema::hasTable('payslips') && $limits->hasModule($tenant, 'hrms.payroll')) {
            $this->assert($this->count('payslips where abs(gross_pay - total_deductions - net_pay) > 0.02') === 0, "$slug: payslip net != gross - deductions");
            $this->assert($this->count('payslips where paid_days > working_days or paid_days < 0') === 0, "$slug: payslip paid days");
            $this->assert($this->count('payroll_runs r where employee_count <> (select count(*) from payslips p where p.payroll_run_id = r.id)') === 0, "$slug: payroll run employee_count mismatch");
            $this->assert($this->count('payslips p join employees e on e.id = p.employee_id join payroll_runs r on r.id = p.payroll_run_id where e.joining_date > r.pay_period_end') === 0, "$slug: payslip before joining");
        }
        if (Schema::hasTable('expense_claims') && $limits->hasModule($tenant, 'hrms.expenses')) {
            $this->assert($this->count('expense_claims c where abs(total_amount - coalesce((select sum(amount) from expense_claim_items i where i.claim_id = c.id), 0)) > 0.01') === 0, "$slug: expense totals differ from items");
        }
        if (Schema::hasTable('assets') && $limits->hasModule($tenant, 'hrms.assets')) {
            $this->assert($this->count("assets where (status = 'assigned') <> (assigned_to_employee_id is not null)") === 0, "$slug: asset status vs holder");
            $this->assert($this->count("(select asset_id from asset_assignments where status = 'active' group by asset_id having count(*) > 1) x") === 0, "$slug: asset with two active assignments");
        }
        if (Schema::hasTable('attendance_days') && $limits->hasModule($tenant, 'hrms.attendance')) {
            $this->assert($this->count('attendance_days a join employees e on e.id = a.employee_id where a.work_date < e.joining_date') === 0, "$slug: attendance before joining");
            $this->assert($this->count("attendance_days a join employees e on e.id = a.employee_id where e.exit_date is not null and a.work_date >= e.exit_date and e.status in ('exited','terminated')") === 0, "$slug: attendance after exit");
        }
    }

    private function tms(string $slug, CarbonImmutable $end, Tenant $tenant): void
    {
        $min = (int) config('demo_seed.projects.min');
        $max = (int) config('demo_seed.projects.max');
        $projects = (int) DB::table('projects')->count();
        $this->assert($projects >= $min && $projects <= $max, "$slug: $projects projects outside $min-$max");

        $tmin = (int) config('demo_seed.tasks_per_project.min');
        $tmax = (int) config('demo_seed.tasks_per_project.max');
        $this->assert($this->count("(select project_id from tasks group by project_id having count(*) < $tmin or count(*) > $tmax) x") === 0, "$slug: a project has a task count outside $tmin-$tmax");
        $this->assert((int) DB::table('tasks')->count() === (int) DB::table('tasks')->whereNull('deleted_at')->count(), "$slug: unexpected deleted tasks");

        $q = fn (string $sql, string $msg) => $this->assert($this->count($sql) === 0, "$slug: $msg");
        $q('tasks t where t.assignee_id is not null and not exists (select 1 from project_members m where m.project_id = t.project_id and m.user_id = t.assignee_id)', 'assignee is not a project member');
        $q('tasks t where not exists (select 1 from project_members m where m.project_id = t.project_id and m.user_id = t.reporter_id)', 'reporter is not a project member');
        $q('tasks t join task_statuses s on s.id = t.status_id where s.project_id <> t.project_id', 'status from another project');
        $q('tasks t join tasks p on p.id = t.parent_id where p.project_id <> t.project_id', 'parent in another project');
        $q('tasks t join tasks p on p.id = t.epic_id where p.project_id <> t.project_id', 'epic in another project');
        $q('tasks t join sprints s on s.id = t.sprint_id where s.project_id <> t.project_id', 'sprint of another project');
        $q('tasks t join task_statuses s on s.id = t.status_id where (s.is_done = true) <> (t.completed_at is not null)', 'completed_at disagrees with the done status');
        $q('tasks where completed_at is not null and completed_at < created_at', 'completed before created');
        $q('tasks t join projects p on p.id = t.project_id where t.created_at < p.created_at', 'task older than its project');
        $q('tasks t join tasks p on p.id = t.parent_id join task_statuses ps on ps.id = p.status_id join task_statuses ts on ts.id = t.status_id where ps.is_done = true and ts.is_done = false', 'open subtask under a finished parent');
        $q('task_dependencies d join tasks t on t.id = d.task_id join tasks b on b.id = d.depends_on_task_id where t.completed_at is not null and (b.completed_at is null or b.completed_at > t.completed_at)', 'finished task still blocked');
        $q('comments c join tasks t on t.id = c.task_id where c.created_at < t.created_at', 'comment before its task');
        $q('task_status_history h join tasks t on t.id = h.task_id where h.changed_at < t.created_at', 'status history before the task');
        $q("projects where (select count(*) from sprints s where s.project_id = projects.id and s.status = 'active') > 1", 'more than one active sprint');
        foreach (['tasks', 'comments', 'activities', 'work_logs', 'notifications'] as $table) {
            if (Schema::hasTable($table)) {
                $over = DB::table($table)->where('created_at', '>', $end->addMinute())->count();
                $this->assert($over === 0, "$slug: $table has $over rows after the data window closed ({$end->toDateString()})");
            }
        }

        if (Schema::hasTable('work_logs')) {
            $q('work_logs w join tasks t on t.id = w.task_id where not exists (select 1 from project_members m where m.project_id = t.project_id and m.user_id = w.user_id)', 'work log by a non-member');
            $q('work_logs w join tasks t on t.id = w.task_id where w.started_at < t.created_at', 'work log before task');
            $q("work_logs where description is null or trim(description) = '' or ended_at is null or ended_at <= started_at", 'invalid work log');
            // one person cannot work two overlapping logs; weekly timesheets equal their logs
            $logs = DB::table('work_logs')->orderBy('user_id')->orderBy('started_at')->get(['user_id', 'started_at', 'ended_at', 'duration_minutes', 'billable']);
            $overlap = 0;
            $badDuration = 0;
            $weeks = [];
            $prev = null;
            foreach ($logs as $w) {
                $s = CarbonImmutable::parse($w->started_at);
                $e = CarbonImmutable::parse($w->ended_at);
                $badDuration += abs($s->diffInMinutes($e, true) - $w->duration_minutes) > 1 ? 1 : 0;
                if ($prev && $prev->user_id === $w->user_id && CarbonImmutable::parse($prev->ended_at)->gt($s)) {
                    $overlap++;
                }
                $prev = $w;
                $key = $w->user_id.'|'.$s->startOfWeek()->toDateString();
                $weeks[$key] = ($weeks[$key] ?? 0) + $w->duration_minutes;
            }
            $this->assert($overlap === 0, "$slug: overlapping work logs ($overlap)");
            $this->assert($badDuration === 0, "$slug: work log duration differs from its start/end ($badDuration)");
            if (Schema::hasTable('timesheets') && DB::table('timesheets')->count() > 0) {
                $mismatch = 0;
                foreach (DB::table('timesheets')->get(['user_id', 'week_start', 'total_minutes']) as $ts) {
                    $mismatch += ($weeks[$ts->user_id.'|'.substr($ts->week_start, 0, 10)] ?? -1) === (int) $ts->total_minutes ? 0 : 1;
                }
                $this->assert($mismatch === 0, "$slug: timesheet totals differ from work logs ($mismatch)");
                $this->assert(count($weeks) === (int) DB::table('timesheets')->count(), "$slug: a week with work logs has no timesheet");
            }
        }
        if (Schema::hasTable('automation_runs')) {
            $q('automation_runs r join automation_rules a on a.id = r.rule_id join tasks t on t.id = r.task_id where t.project_id <> a.project_id', 'automation run on a task of another project');
            $q('automation_rules a where a.run_count <> (select count(*) from automation_runs r where r.rule_id = a.id)', 'automation run_count mismatch');
        }
        if (Schema::hasTable('sprints')) {
            $q("sprints where status = 'completed' and completed_points is null", 'completed sprint without completed points');
            $q('sprints where end_date < start_date', 'sprint ends before it starts');
        }
    }

    /** Number of rows produced by "select count(*) from $from". */
    private function count(string $from): int
    {
        return (int) DB::selectOne("select count(*) as c from $from")->c;
    }

    private function assert(bool $ok, string $message): void
    {
        if (! $ok) {
            $this->failures[] = $message;
        }
    }
}
