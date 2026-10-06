<?php

namespace Database\Seeders;

use App\Models\Comment;
use App\Models\Priority;
use App\Models\Project;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\Tenant;
use App\Models\TenantUserRouting;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WorkLog;
use App\Models\Workspace;
use App\Services\TenantLifecycle;
use App\Support\TenantDatabaseManager;
use App\Support\TenantProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Factory-built scale seed: the relational mirror of ScaleDataSeeder.
 *
 * Every row travels through an Eloquent factory (never a bulk
 * `DB::table()->insert()`), so relations are real model links — members
 * attached through pivots, statuses from the catalog, tasks keyed per
 * project, comments/logs/notifications pointing at actual rows. That
 * honesty costs speed (one INSERT per row); the totals table at the end
 * reports the elapsed time next to the counts.
 *
 * The run starts by removing every tenant (databases + central rows) and
 * reseeding from scratch: demo tenants through TenantSeeder, scale tenants
 * as `tenant-001…`. Platform rows (users, plans, settings, pages, audit
 * history) are never touched. Invoke only explicitly:
 * `php artisan db:seed --database=system --class=FactoryScaleSeeder`
 * (or the `tenants:seed-factory` command, which requires --force).
 */
class FactoryScaleSeeder extends Seeder
{
    private const STATUS_WEIGHTS = [15, 20, 30, 15, 20];

    /**
     * @param  int|int[]  $workspaceCounts  One count, or a [min, max] range.
     */
    public function run(
        int $tenants = 100,
        int $usersPerTenant = 10,
        int|array $workspacesPerTenant = 5,
        int|array $projectsPerWorkspace = 5,
        int $tasksPerProject = 100,
        bool $related = true,
        ?int $seed = null,
    ): void {
        $startedAt = microtime(true);

        if ($seed !== null) {
            mt_srand($seed);
        }

        $dbm = app(TenantDatabaseManager::class);

        $this->wipeTenants($dbm);

        app(TenantSeeder::class)->run($dbm, app(TenantProvisioner::class), app(TenantLifecycle::class));

        $statusConfig = collect(config('task_statuses.statuses'));
        $totals = ['tenants' => 0, 'users' => 0, 'workspaces' => 0, 'projects' => 0, 'tasks' => 0];

        $bar = $this->command ? $this->command->getOutput()->createProgressBar($tenants) : null;

        for ($t = 1; $t <= $tenants; $t++) {
            $slug = 'tenant-'.str_pad((string) $t, 3, '0', STR_PAD_LEFT);
            $tenant = Tenant::factory()->create(['name' => "Org {$slug}", 'slug' => $slug]);

            app(TenantProvisioner::class)->provisionIsolated($tenant, $dbm, app(TenantLifecycle::class));

            $counts = $dbm->using($tenant, fn (): array => DB::transaction(function () use ($tenant, $usersPerTenant, $workspacesPerTenant, $projectsPerWorkspace, $tasksPerProject, $related, $statusConfig): array {
                $users = $this->seedUsers($tenant, $usersPerTenant);

                $workspaces = 0;
                $projects = 0;
                $tasks = 0;

                for ($w = 1; $w <= $this->resolveCount($workspacesPerTenant); $w++) {
                    $workspace = $this->seedWorkspace($tenant, $users, $w);
                    $workspaces++;

                    for ($p = 1; $p <= $this->resolveCount($projectsPerWorkspace); $p++) {
                        $project = $this->seedProject($tenant, $workspace, $users, $w, $p);
                        $projects++;

                        $tasks += $this->seedTasks($project, $users, $statusConfig, $tasksPerProject, $related);
                    }
                }

                return [
                    'users' => count($users),
                    'workspaces' => $workspaces,
                    'projects' => $projects,
                    'tasks' => $tasks,
                ];
            }));

            app(TenantProvisioner::class)->syncRouting($dbm, $tenant);

            $totals['tenants']++;

            foreach ($counts as $key => $value) {
                $totals[$key] += $value;
            }

            $bar?->advance();
        }

        $bar?->finish();
        $this->command?->newLine();

        $totals['elapsed'] = round(microtime(true) - $startedAt, 1);

        $this->command?->info('Factory scale seeding complete.');
        $this->report($totals);
    }

    /**
     * Remove every tenant: drop each tenant database, then delete the
     * central rows (subscriptions/events cascade, routing and provisioning
     * runs cascade, the subscription pointer nulls). Platform rows —
     * users, plans, settings, pages, audit and impersonation history —
     * are never touched.
     */
    private function wipeTenants(TenantDatabaseManager $dbm): void
    {
        $dbm->connectSystem();

        foreach (Tenant::all() as $tenant) {
            $dbm->dropDatabase($tenant);

            DB::transaction(function () use ($tenant): void {
                SubscriptionEvent::where('tenant_id', $tenant->id)->delete();
                Subscription::where('tenant_id', $tenant->id)->delete();
                TenantUserRouting::where('tenant_id', $tenant->id)->delete();
                // forceDelete: tenants soft-delete, and a trashed row still
                // owns its slug — firstOrCreate would miss it on SELECT and
                // collide on INSERT.
                $tenant->forceDelete();
            });
        }

        if (Schema::hasTable('impersonation_logs') && Schema::hasColumn('impersonation_logs', 'tenant_id')) {
            DB::table('impersonation_logs')->whereNotNull('tenant_id')->delete();
        }
    }

    /**
     * @return list<User> Owner first, then factory users with cycling roles.
     */
    private function seedUsers(Tenant $tenant, int $count): array
    {
        // The provisioner already created the tenant owner as an admin; it
        // counts toward $count, so factory users fill the remainder.
        $owner = User::where('email', "owner@{$tenant->slug}.test")->firstOrFail();
        $users = [$owner];

        $roleSlugs = Role::orderBy('name')->pluck('slug')->all();

        for ($u = 1; $u <= max(0, $count - 1); $u++) {
            $user = User::factory()->create([
                'name' => "Agent {$tenant->slug} {$u}",
                'email' => "user-{$tenant->slug}-{$u}@example.test",
            ]);

            if ($roleSlugs !== []) {
                $user->roles()->syncWithoutDetaching([
                    Role::where('slug', $roleSlugs[($u - 1) % count($roleSlugs)])->value('id'),
                ]);
            }

            $users[] = $user;
        }

        return $users;
    }

    private function seedWorkspace(Tenant $tenant, array $users, int $ordinal): Workspace
    {
        $owner = $users[0];

        return Workspace::factory()
            ->withMembers($users, $owner->id)
            ->create([
                'name' => "{$tenant->name} Workspace {$ordinal}",
                'slug' => "{$tenant->slug}-w{$ordinal}",
                'description' => "Auto-generated workspace {$ordinal} for {$tenant->name}.",
                'created_by' => $owner->id,
            ]);
    }

    private function seedProject(Tenant $tenant, Workspace $workspace, array $users, int $w, int $p): Project
    {
        $lead = $users[0];

        return Project::factory()
            ->withKey(strtoupper(substr($tenant->slug, -3))."-{$w}-{$p}")
            ->withStatuses()
            ->withMembers($users, $lead->id)
            ->create([
                'workspace_id' => $workspace->id,
                'created_by' => $lead->id,
                'lead_user_id' => $lead->id,
                'name' => "{$tenant->name} Project {$w}.{$p}",
                'description' => "Generated project {$w}.{$p} for {$tenant->name}.",
            ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $statusConfig
     */
    private function seedTasks(Project $project, array $users, $statusConfig, int $perProject, bool $related): int
    {
        $statuses = TaskStatus::where('project_id', $project->id)->orderBy('position')->get();
        $priorities = Priority::orderBy('id')->pluck('id')->all();
        $userIds = array_map(fn (User $user) => $user->id, $users);

        $counts = $this->distribute($perProject, $statuses->count());
        $created = [];

        $position = [];
        $sequence = 0;

        for ($i = 1; $i <= $perProject; $i++) {
            $sequence++;

            $status = $this->statusFor($statuses, $counts, $i);
            $isDone = (bool) ($statusConfig->first(
                fn ($item) => $item['slug'] === $status->slug && ($item['is_done'] ?? false)
            ) !== null);

            $made = now()->subDays(random_int(1, 120))->subMinutes(random_int(0, 7200));
            $position[$status->id] ??= 1;

            $task = Task::factory()
                ->forProject($project)
                ->withKey("{$project->key}-{$sequence}", $sequence)
                ->forStatus($status)
                ->assignedTo($i % 8 === 0 ? null : $users[($i - 1) % count($users)])
                ->state([
                    'created_by' => $users[0]->id,
                    'reporter_id' => $users[0]->id,
                    'priority_id' => $priorities[($i - 1) % count($priorities)],
                    'title' => fake()->sentence(4),
                    'description' => fake()->paragraph(),
                    'due_date' => $isDone ? null : $made->copy()->addDays(random_int(1, 30))->toDateString(),
                    'estimate_minutes' => $i % 3 === 0 ? null : random_int(30, 1440),
                    'position' => $position[$status->id]++,
                    'completed_at' => $isDone ? $made->copy()->addDays(random_int(0, 20))->toDateTimeString() : null,
                    'created_at' => $made->toDateTimeString(),
                    'updated_at' => $made->copy()->addDays(random_int(0, 5))->toDateTimeString(),
                ])
                ->create();

            $created[] = $task;
        }

        $project->update(['last_task_sequence' => $perProject]);

        if ($related) {
            $this->seedRelated($project, $users, $created);
        }

        return count($created);
    }

    /**
     * @param  list<Task>  $tasks
     */
    private function seedRelated(Project $project, array $users, array $tasks): void
    {
        $workers = array_values(array_slice($users, 0, max(1, count($users) - 2)));
        $actor = $users[0];

        foreach ($tasks as $offset => $task) {
            $worker = $workers[$offset % count($workers)];

            if ($offset % 7 === 0) {
                Comment::factory()->forTask($task)->by($worker)->create();
            }

            if (($offset + 1) % 5 === 0) {
                WorkLog::factory()->forTask($task)->by($worker)->create([
                    'started_at' => $task->created_at,
                    'ended_at' => $task->created_at,
                    'duration_minutes' => 120,
                    'description' => 'Implementation and verification.',
                ]);
            }
        }

        foreach (array_values($workers) as $u => $worker) {
            $task = $tasks[($u * 4) % count($tasks)];

            UserNotification::factory()->forUser($worker)->from($actor)->create([
                'type' => 'task.assigned',
                'data' => [
                    'task_id' => $task->id,
                    'key' => $task->key,
                    'title' => $task->title,
                    'project_id' => $project->id,
                    'project_name' => $project->name,
                    'workspace_id' => $project->workspace_id,
                ],
            ]);
        }
    }

    /**
     * @param  Collection<int, TaskStatus>  $statuses
     * @param  list<int>  $counts
     */
    private function statusFor($statuses, array $counts, int $i): TaskStatus
    {
        $remaining = $i;

        foreach ($statuses->values() as $idx => $status) {
            if ($remaining <= $counts[$idx]) {
                return $status;
            }

            $remaining -= $counts[$idx];
        }

        return $statuses->last();
    }

    /**
     * Deterministically splits $total into $slots parts so they sum to $total.
     *
     * @return list<int>
     */
    private function distribute(int $total, int $slots): array
    {
        $weights = array_slice(self::STATUS_WEIGHTS, 0, $slots);
        $sum = array_sum($weights);

        $counts = [];
        foreach ($weights as $weight) {
            $counts[] = (int) floor($total * $weight / $sum);
        }

        while (array_sum($counts) < $total) {
            $counts[0]++;
        }

        return $counts;
    }

    private function resolveCount(int|array $spec): int
    {
        if (is_int($spec)) {
            return max(1, $spec);
        }

        [$min, $max] = [max(1, (int) ($spec[0] ?? 1)), max(1, (int) ($spec[1] ?? $spec[0] ?? 1))];

        if ($max < $min) {
            [$min, $max] = [$max, $min];
        }

        return $min === $max ? $min : mt_rand($min, $max);
    }

    /**
     * @param  array<string, int|float>  $totals
     */
    private function report(array $totals): void
    {
        if (! $this->command) {
            return;
        }

        $rows = [];
        foreach (['tenants', 'users', 'workspaces', 'projects', 'tasks'] as $label) {
            $rows[] = [ucfirst($label), number_format($totals[$label])];
        }
        $rows[] = ['Elapsed', $totals['elapsed'].'s'];

        $this->command->table(['Metric', 'Total'], $rows);
    }
}
