<?php

namespace Database\Seeders;

use App\Models\Priority;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Role;
use App\Models\SystemUser;
use App\Models\TaskStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\TenantLifecycle;
use App\Support\TenantDatabaseManager;
use App\Support\TenantProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ScaleDataSeeder extends Seeder
{
    private const STATUS_WEIGHTS = [15, 20, 30, 15, 20];

    private const TASK_TITLES = [
        'Fix login form validation',
        'Add CSV export',
        'Improve dashboard load time',
        'Refactor notification service',
        'Redesign account settings page',
        'Build API rate limiting',
        'Fix mobile navigation overlap',
        'Add dark mode toggle',
        'Optimize search queries',
        'Migrate legacy import flow',
        'Add two-factor auth',
        'Update onboarding checklist',
        'Fix websocket reconnection',
        'Add bulk task actions',
        'Improve empty state copy',
        'Add weekly digest email',
        'Fix timezone handling in reports',
        'Add project templates',
        'Improve drag-and-drop perf',
        'Add keyboard shortcuts',
    ];

    private const DESCRIPTIONS = [
        'Users reported inconsistent behaviour across desktop and mobile. Reproduce, isolate the failing branch and add regression coverage before deploying.',
        'Product wants a way to pull this data for the quarterly review. Build it behind a feature flag so it can be rolled out gradually.',
        'Page is noticeably slow past 1k records. Profile the hot path, consolidate the queries and verify the fix with the load tooling.',
        'Keep the public behaviour identical; only the internals change. Coordinator with the platform team on the release window.',
        'QA found a few edge cases around empty and oversized input. Tighten validation and surface friendly inline errors.',
        'This blocks the external integration launch. Confirm the contract, stub the upstream and wire the happy path end to end.',
        'Minor regression after the last layout change. Check the responsive breakpoints and restore the intended stacking order.',
        'Gather feedback on the current contrast ratios and iterate on the palette before rolling out to everyone.',
        'Slow under heavy filters. Review the index usage and flatten the worst offenders before the next release train.',
        'Backfill covers the current schema but the legacy importer is still brittle. Harden it and add fixtures for each known format.',
    ];

    public function run(
        int $tenants = 100,
        int $usersPerTenant = 10,
        int $workspacesPerTenant = 5,
        int $projectsPerWorkspace = 5,
        int $tasksPerProject = 100,
        bool $related = true,
    ): void {
        $dbm = app(TenantDatabaseManager::class);
        $provisioner = app(TenantProvisioner::class);
        $lifecycle = app(TenantLifecycle::class);

        $dbm->connectSystem();
        $this->createSuperAdmin();

        $statusConfig = collect(config('task_statuses.statuses'));

        $bar = $this->command ? $this->command->getOutput()->createProgressBar($tenants) : null;

        for ($t = 1; $t <= $tenants; $t++) {
            $slug = 'tenant-'.str_pad((string) $t, 3, '0', STR_PAD_LEFT);
            $tenant = $this->firstOrCreateTenant($slug, $t);
            $provisioner->provisionIsolated($tenant, $dbm, $lifecycle);

            $dbm->using($tenant, function () use ($tenant, $usersPerTenant, $workspacesPerTenant, $projectsPerWorkspace, $tasksPerProject, $related, $statusConfig): void {
                $roleIds = Role::pluck('id', 'slug');
                $priorityIds = Priority::pluck('id', 'slug');
                $projectRoleIds = ProjectRole::pluck('id', 'slug');

                $users = $this->createUsers($tenant, $usersPerTenant);

                for ($w = 1; $w <= $workspacesPerTenant; $w++) {
                    $workspace = $this->firstOrCreateWorkspace($tenant, $w);

                    if ($workspace->members()->count() === 0) {
                        $this->attachWorkspaceMembers($workspace, $users);
                    }

                    for ($p = 1; $p <= $projectsPerWorkspace; $p++) {
                        $project = $this->firstOrCreateProject($tenant, $workspace, $users, $w, $p, $projectRoleIds);

                        if ($project->tasks()->count() === 0) {
                            $this->seedTasks(
                                $workspace,
                                $project,
                                $users,
                                $statusConfig,
                                $priorityIds,
                                $tasksPerProject,
                                $related,
                            );
                        }
                    }
                }
            });

            // Mirror users created above into the central routing index (idempotent).
            $provisioner->syncRouting($dbm, $tenant);

            $bar?->advance();
        }

        $bar?->finish();
        $this->command?->newLine();
        $this->command?->info('Scale data seeding complete.');
    }

    private function createSuperAdmin(): void
    {
        SystemUser::firstOrCreate(
            ['email' => 'superadmin@flowsync.test'],
            [
                'name' => 'Super Admin',
                'password' => 'password',
                'is_super_admin' => true,
            ]
        );
    }

    private function firstOrCreateTenant(string $slug, int $index): Tenant
    {
        return Tenant::firstOrCreate(
            ['slug' => $slug],
            ['name' => "Org {$index}", 'description' => "Generated demo organisation {$index}"]
        );
    }

    private function firstOrCreateWorkspace(Tenant $tenant, int $ordinal): Workspace
    {
        $slug = "{$tenant->slug}-w{$ordinal}";
        $owner = User::where('email', "owner@{$tenant->slug}.test")->first();

        return Workspace::firstOrCreate(
            ['slug' => $slug],
            [
                'created_by' => $owner?->id,
                'name' => "{$tenant->name} Workspace {$ordinal}",
                'slug' => $slug,
                'description' => "Auto-generated workspace {$ordinal} for {$tenant->name}.",
            ]
        );
    }

    private function createUsers(Tenant $tenant, int $count): array
    {
        // The provisioner already created the tenant owner as an admin; it
        // counts toward $count, so we add $count - 1 more users per tenant.
        $owner = User::where('email', "owner@{$tenant->slug}.test")->first();

        $users = [];
        $roleSlugs = Role::orderBy('name')->pluck('slug')->all();

        if (empty($roleSlugs)) {
            return [$owner];
        }

        $extra = max(0, $count - 1);
        for ($u = 1; $u <= $extra; $u++) {
            $email = "user-{$tenant->slug}-{$u}@example.test";
            $user = User::firstOrCreate(
                ['email' => $email],
                ['name' => "Agent {$tenant->slug} {$u}", 'password' => 'password']
            );

            $roleSlug = $roleSlugs[($u - 1) % count($roleSlugs)];
            if ($roleId = Role::where('slug', $roleSlug)->value('id')) {
                $user->roles()->syncWithoutDetaching([$roleId]);
            }

            $users[] = $user;
        }

        array_unshift($users, $owner);

        return $users;
    }

    private function attachWorkspaceMembers(Workspace $workspace, array $users): void
    {
        $rows = [];

        foreach (array_values($users) as $i => $user) {
            $role = $i === 0 ? 'owner' : ($i === 1 ? 'admin' : 'member');
            $now = now()->toDateTimeString();
            $rows[] = [
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'role' => $role,
                'added_by' => $users[0]->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('workspace_members')->insert($rows);
    }

    private function firstOrCreateProject(
        Tenant $tenant,
        Workspace $workspace,
        array $users,
        int $w,
        int $p,
        $projectRoleIds,
    ): Project {
        $key = strtoupper(substr($tenant->slug, -3))."-{$w}-{$p}";
        $lead = $users[0];

        $project = Project::firstOrCreate(
            ['key' => $key],
            [
                'workspace_id' => $workspace->id,
                'created_by' => $lead->id,
                'lead_user_id' => $lead->id,
                'name' => "{$tenant->name} Project {$w}.{$p}",
                'description' => "Generated project {$w}.{$p} for {$tenant->name}.",
            ]
        );

        if ($project->statuses()->count() === 0) {
            $position = 0;
            foreach (config('task_statuses.statuses') as $status) {
                $position++;
                TaskStatus::create([
                    'project_id' => $project->id,
                    'name' => $status['name'],
                    'slug' => $status['slug'],
                    'category' => $status['category'],
                    'position' => $status['position'],
                    'color' => $status['color'],
                    'is_default' => $status['is_default'] ?? false,
                    'is_done' => (bool) $status['is_done'],
                ]);
            }
        }

        if ($project->members()->count() === 0) {
            $leadRoleId = $projectRoleIds->get('lead') ?? $projectRoleIds->first();
            $devRoleId = $projectRoleIds->get('developer') ?? $projectRoleIds->first();
            $viewerRoleId = $projectRoleIds->get('viewer') ?? $projectRoleIds->first();

            if ($leadRoleId !== null && $devRoleId !== null && $viewerRoleId !== null) {
                $rows = [];
                foreach (array_values($users) as $i => $user) {
                    $roleId = $i === 0 ? $leadRoleId : ($i % 2 === 1 ? $devRoleId : $viewerRoleId);
                    $now = now()->toDateTimeString();
                    $rows[] = [
                        'project_id' => $project->id,
                        'user_id' => $user->id,
                        'project_role_id' => $roleId,
                        'added_by' => $users[0]->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                DB::table('project_members')->insert($rows);
            }
        }

        return $project;
    }

    private function seedTasks(
        Workspace $workspace,
        Project $project,
        array $users,
        Collection $statusConfig,
        $priorityIds,
        int $tasksPerProject,
        bool $related,
    ): void {
        $statusIds = TaskStatus::where('project_id', $project->id)
            ->orderBy('position')
            ->pluck('id', 'slug');

        $statusSlugs = $statusIds->keys()->all();
        $priorities = array_values($priorityIds->all());
        $userIds = array_map(fn (User $user) => $user->id, $users);

        $counts = $this->distribute($tasksPerProject, count($statusSlugs));

        $now = now();
        $rows = [];
        $sequence = 0;
        $columnPositions = array_fill(0, count($statusSlugs), 1);

        for ($i = 1; $i <= $tasksPerProject; $i++) {
            $sequence++;

            $statusIdx = null;
            $remaining = $i;
            foreach ($counts as $idx => $count) {
                if ($remaining <= $count) {
                    $statusIdx = $idx;
                    break;
                }
                $remaining -= $count;
            }
            $statusIdx ??= count($statusSlugs) - 1;

            $statusSlug = $statusSlugs[$statusIdx];
            $statusIsDone = $statusConfig->first(
                fn ($status) => $status['slug'] === $statusSlug && ($status['is_done'] ?? false)
            ) !== null;

            $created = $now->copy()->subDays(random_int(1, 120))->subMinutes(random_int(0, 7200));
            $assigneeId = $i % 8 === 0 ? null : $userIds[($i - 1) % count($userIds)];

            $rows[] = [
                'workspace_id' => $workspace->id,
                'project_id' => $project->id,
                'created_by' => $users[0]->id,
                'reporter_id' => $users[0]->id,
                'assignee_id' => $assigneeId,
                'status_id' => $statusIds->get($statusSlug),
                'priority_id' => $priorities[($i - 1) % count($priorities)],
                'key' => "{$project->key}-{$sequence}",
                'sequence' => $sequence,
                'title' => self::TASK_TITLES[($i - 1) % count(self::TASK_TITLES)],
                'description' => self::DESCRIPTIONS[($i - 1) % count(self::DESCRIPTIONS)],
                'due_date' => $statusIsDone ? null : $created->copy()->addDays(random_int(1, 30))->toDateString(),
                'estimate_minutes' => $i % 3 === 0 ? null : random_int(30, 1440),
                'position' => $columnPositions[$statusIdx]++,
                'completed_at' => $statusIsDone ? $created->copy()->addDays(random_int(0, 20))->toDateTimeString() : null,
                'created_at' => $created->toDateTimeString(),
                'updated_at' => $created->copy()->addDays(random_int(0, 5))->toDateTimeString(),
            ];
        }

        DB::table('tasks')->insert($rows);

        $lastId = DB::getPdo()->lastInsertId();
        $baseId = $lastId - ($tasksPerProject - 1);

        if ($related) {
            $this->seedRelated($project, $users, $baseId, $tasksPerProject, $statusConfig, $statusIds);
        }

        $project->update(['last_task_sequence' => $tasksPerProject]);
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

    private function seedRelated(
        Project $project,
        array $users,
        int $baseId,
        int $tasksPerProject,
        Collection $statusConfig,
        $statusIds,
    ): void {
        $workers = array_slice($users, 0, max(1, count($users) - 2));
        $actorId = $users[0]->id ?? null;

        $comments = [];
        $logs = [];
        $notifications = [];

        $statusSlugs = $statusIds->keys()->all();
        $doneSlug = null;
        foreach ($statusSlugs as $slug) {
            if ($statusConfig->first(fn ($s) => $s['slug'] === $slug && ($s['is_done'] ?? false))) {
                $doneSlug = $slug;
            }
        }

        for ($offset = 0; $offset < $tasksPerProject; $offset++) {
            $taskId = $baseId + $offset;
            $worker = $workers[$offset % count($workers)];
            $created = now()->subDays(random_int(1, 120))->toDateTimeString();

            if ($offset % 7 === 0) {
                $comments[] = [
                    'task_id' => $taskId,
                    'user_id' => $worker->id,
                    'comment' => 'Noted — I will pick this up. Checked the repro steps and they look solid.',
                    'created_at' => $created,
                    'updated_at' => $created,
                ];
            }

            if (($offset + 1) % 5 === 0 && $doneSlug) {
                $logs[] = [
                    'task_id' => $taskId,
                    'user_id' => $worker->id,
                    'started_at' => $created,
                    'ended_at' => $created,
                    'duration_minutes' => 120,
                    'description' => 'Implementation and verification.',
                    'created_at' => $created,
                    'updated_at' => $created,
                ];
            }
        }

        if ($comments) {
            DB::table('comments')->insert($comments);
        }
        if ($logs) {
            DB::table('work_logs')->insert($logs);
        }

        foreach (array_values($workers) as $u => $worker) {
            $offset = ($u * 4) % $tasksPerProject;
            $taskId = $baseId + $offset;
            $sequence = $offset + 1;
            $taskKey = "{$project->key}-{$sequence}";
            $createdAt = now()->subHours(random_int(1, 240))->toDateTimeString();

            $notifications[] = [
                'user_id' => $worker->id,
                'actor_id' => $actorId,
                'type' => 'task.assigned',
                'data' => json_encode([
                    'task_id' => $taskId,
                    'key' => $taskKey,
                    'title' => self::TASK_TITLES[($sequence - 1) % count(self::TASK_TITLES)],
                    'project_id' => $project->id,
                    'project_name' => $project->name,
                    'workspace_id' => $project->workspace_id,
                ]),
                'read_at' => null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        }

        if ($notifications) {
            DB::table('notifications')->insert($notifications);
        }
    }
}
