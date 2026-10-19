<?php

namespace Database\Seeders\Realistic;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Task-management data of one tenant: workspaces, projects (+members, statuses, components, versions, sprints,
 * automation), and for every project 150-160 tasks with a believable lifecycle. Everything derived from a task —
 * history, comments, work logs, timesheets, activity, notifications — is generated from the same in-memory task
 * rows, so dates, statuses, assignees and progress can never contradict each other.
 */
final class TmsSeeder
{
    private const STATUSES = [
        'backlog' => ['Backlog', 'backlog', 1, '#64748b', false],
        'to-do' => ['To Do', 'todo', 2, '#3b82f6', false],
        'in-progress' => ['In Progress', 'in_progress', 3, '#f59e0b', false],
        'in-review' => ['In Review', 'in_review', 4, '#8b5cf6', false],
        'done' => ['Done', 'done', 5, '#10b981', true],
    ];

    private const BOARD_STATUSES = [
        'kanban' => ['backlog', 'to-do', 'in-progress', 'in-review', 'done'],
        'scrum' => ['backlog', 'to-do', 'in-progress', 'in-review', 'done'],
        'basic' => ['to-do', 'in-progress', 'done'],
    ];

    private const WORKSPACES = ['Product Delivery', 'Customer Projects', 'Internal Operations', 'Platform Engineering', 'Growth & Marketing'];

    private const PROJECT_COLORS = ['#2563eb', '#0d9488', '#7c3aed', '#dc2626', '#ea580c', '#0891b2', '#65a30d', '#c026d3'];

    private Bulk $bulk;

    private TenantRun $run;

    /** @var array<string, int> */
    private array $issueTypes = [];

    /** @var array<string, int> */
    private array $priorities = [];

    /** @var array<string, int> */
    private array $projectRoles = [];

    /** @var array<string, int> sequence cursor for work-log scheduling: "user|date" => minutes after midnight */
    private array $dayCursor = [];

    /** @var array<string, array{minutes: int, billable: int}> "user|weekStart" => totals */
    private array $weeks = [];

    private int $keySeq = 0;

    /** @var array<string, true> */
    private array $usedKeys = [];

    public function seed(TenantRun $run): void
    {
        $this->run = $run;
        $this->bulk = $run->bulk;
        $tl = $run->tl;

        $this->issueTypes = DB::table('issue_types')->pluck('id', 'slug')->all();
        $this->priorities = DB::table('priorities')->pluck('id', 'slug')->all();
        $this->projectRoles = DB::table('project_roles')->pluck('id', 'slug')->all();

        Rng::seed($run->bp['index'], 'tms-structure');
        $workspaces = $this->workspaces();
        $projects = $this->projects($workspaces);

        foreach ($projects as $i => &$project) {
            Rng::seed($run->bp['index'], 'tms-project-'.$i);
            $this->members($project, $workspaces[$project['workspace_idx']]);
            $this->statuses($project);
            $this->components($project);
            $this->versions($project);
        }
        unset($project);

        $this->bulk->insert('projects', array_map(fn ($p) => $p['row'], $projects));
        $this->bulk->insert('project_members', array_merge(...array_map(fn ($p) => $p['member_rows'], $projects)));
        $this->bulk->insert('task_statuses', array_merge(...array_map(fn ($p) => $p['status_rows'], $projects)));
        $this->bulk->insert('project_components', array_merge(...array_map(fn ($p) => $p['component_rows'], $projects)));
        $this->bulk->insert('project_versions', array_merge(...array_map(fn ($p) => $p['version_rows'], $projects)));

        foreach ($projects as $i => &$project) {
            Rng::seed($run->bp['index'], 'tms-tasks-'.$i);
            $this->projectWork($project, $workspaces[$project['workspace_idx']]);
        }
        unset($project);

        $this->timesheets();
        $this->userSettingsAndFilters($projects);
    }

    // ------------------------------------------------------------------ workspaces

    /** @return list<array<string, mixed>> */
    private function workspaces(): array
    {
        $run = $this->run;
        $tl = $run->tl;
        $count = $run->bp['workspaces'];
        $owner = $run->people[0];
        $rows = [];
        $out = [];
        $memberRows = [];
        $labelRows = [];

        $heads = array_values(array_filter($run->people, fn ($p) => $p['level'] >= 3));
        $names = self::WORKSPACES;
        for ($w = 0; $w < $count; $w++) {
            $name = $names[$w % count($names)].($w >= count($names) ? ' '.($w + 1) : '');
            $id = $this->bulk->id('workspaces');
            $created = $tl->created->addMinutes(10 + $w * 7);
            $rows[] = [
                'id' => $id, 'created_by' => $owner['user_id'], 'name' => $name, 'slug' => Str::slug($name),
                'description' => "<p>$name for {$run->bp['name']}.</p>", 'timezone' => $run->bp['country'][2],
                'color' => self::PROJECT_COLORS[$w % count(self::PROJECT_COLORS)], 'created_at' => $created, 'updated_at' => $created,
            ];

            // members: owner + heads + a share of everyone else
            $members = [$owner['user_id'] => 'owner'];
            foreach ($heads as $h) {
                $members[$h['user_id']] = $h['level'] === 4 ? 'admin' : 'member';
            }
            $share = 0.35 + 0.5 / ($w + 1);
            foreach ($run->people as $p) {
                if (! isset($members[$p['user_id']]) && ! in_array('viewer', $p['roles'], true) && Rng::chance($share)) {
                    $members[$p['user_id']] = 'member';
                }
                if (! isset($members[$p['user_id']]) && in_array('viewer', $p['roles'], true) && Rng::chance($share * 0.5)) {
                    $members[$p['user_id']] = 'member';
                }
            }
            foreach ($members as $uid => $role) {
                $memberRows[] = ['workspace_id' => $id, 'user_id' => $uid, 'role' => $role, 'added_by' => $owner['user_id'], 'created_at' => $created, 'updated_at' => $created];
            }

            $labels = [];
            foreach (NameBank::LABELS as [$label, $color]) {
                $labelId = $this->bulk->id('labels');
                $labels[$label] = $labelId;
                $labelRows[] = ['id' => $labelId, 'workspace_id' => $id, 'name' => $label, 'color' => $color, 'created_at' => $created, 'updated_at' => $created];
            }

            $out[] = ['id' => $id, 'name' => $name, 'members' => $members, 'labels' => $labels, 'created' => $created];
        }

        $this->bulk->insert('workspaces', $rows);
        $this->bulk->insert('workspace_members', $memberRows);
        $this->bulk->insert('labels', $labelRows);

        return $out;
    }

    // ------------------------------------------------------------------ projects

    /** @param list<array<string, mixed>> $workspaces @return list<array<string, mixed>> */
    private function projects(array $workspaces): array
    {
        $run = $this->run;
        $tl = $run->tl;
        $out = [];
        $usedNames = [];
        $count = $run->bp['projects'];
        $window = max(1, $tl->days());
        $min = (int) config('demo_seed.tasks_per_project.min');
        $max = max($min, (int) config('demo_seed.tasks_per_project.max'));

        for ($i = 0; $i < $count; $i++) {
            $wsIdx = $i % count($workspaces);
            $area = NameBank::PROJECT_AREAS[($i * 5 + $run->bp['index']) % count(NameBank::PROJECT_AREAS)];
            $phase = NameBank::PROJECT_PHASES[intdiv($i, count(NameBank::PROJECT_AREAS)) % count(NameBank::PROJECT_PHASES)];
            $name = $i < count(NameBank::PROJECT_AREAS) ? $area : "$area — $phase";
            while (isset($usedNames[$name])) {
                $name .= ' II';
            }
            $usedNames[$name] = true;

            $start = CarbonImmutable::instance(Rng::between($tl->created, $tl->created->addSeconds((int) ($window * 86400 * 0.55)), 1.4))->startOfDay()->setTime(9, 0);
            if ($tl->end->diffInDays($start, true) < 3) {
                $start = $tl->created->addMinutes(30);
            }
            $archived = $i > 4 && $window > 200 && Rng::chance(0.05);
            $board = (string) Rng::weighted(['kanban' => 45, 'scrum' => 40, 'basic' => 15]);
            $key = $this->projectKey($name);
            $lead = Rng::pick($this->leadCandidates());
            $id = $this->bulk->id('projects');
            $created = $start->subDays(mt_rand(0, 2));
            if ($created->lt($tl->created)) {
                $created = $tl->created->addMinutes(20);
            }
            if ($start->lt($created->addMinutes(10))) {
                $start = $created->addMinutes(10); // work starts after the project exists
            }

            $out[] = [
                'id' => $id, 'idx' => $i, 'workspace_idx' => $wsIdx, 'workspace_id' => $workspaces[$wsIdx]['id'], 'name' => $name, 'key' => $key,
                'board' => $board, 'start' => $start, 'created' => $created, 'archived' => $archived, 'lead' => $lead['user_id'],
                'task_count' => mt_rand($min, $max), 'billable' => Rng::chance(0.55),
                'row' => [
                    'id' => $id, 'workspace_id' => $workspaces[$wsIdx]['id'], 'created_by' => $lead['user_id'], 'lead_user_id' => $lead['user_id'],
                    'name' => $name, 'key' => $key, 'description' => "<p>$name — delivery plan, backlog and release tracking for the {$workspaces[$wsIdx]['name']} workspace.</p>",
                    'start_date' => $start->toDateString(), 'due_date' => $start->addDays(mt_rand(75, 260))->toDateString(),
                    'last_task_sequence' => 0, 'archived_at' => $archived ? $tl->end->subDays(mt_rand(5, 60)) : null,
                    'created_at' => $created, 'updated_at' => $tl->end, 'color' => self::PROJECT_COLORS[$i % count(self::PROJECT_COLORS)],
                    'enforce_workflow' => false, 'board_type' => $board,
                ],
                'member_rows' => [], 'status_rows' => [], 'component_rows' => [], 'version_rows' => [],
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> managers and leads */
    private function leadCandidates(): array
    {
        return array_values(array_filter($this->run->people, fn ($p) => $p['level'] >= 3 && $p['idx'] !== 0)) ?: [$this->run->people[0]];
    }

    private function projectKey(string $name): string
    {
        $words = preg_split('/[\s—&-]+/', Str::upper(Str::ascii($name)), -1, PREG_SPLIT_NO_EMPTY);
        $base = '';
        foreach ($words as $w) {
            $base .= $w[0];
        }
        $base = substr(str_pad($base, 2, 'X'), 0, 4);
        $key = $base;
        for ($n = 2; isset($this->usedKeys[$key]); $n++) {
            $key = substr($base, 0, 3).$n;
        }
        $this->usedKeys[$key] = true;

        return $key;
    }

    /** @param array<string, mixed> $project @param array<string, mixed> $workspace */
    private function members(array &$project, array $workspace): void
    {
        $run = $this->run;
        $byId = [];
        foreach ($run->people as $p) {
            $byId[$p['user_id']] = $p;
        }
        $pool = array_keys($workspace['members']);
        $size = min(count($pool), mt_rand(7, 18));
        $chosen = Rng::sample($pool, $size);
        $chosen = array_values(array_unique(array_merge([$project['lead']], $chosen)));

        $members = [];
        $rows = [];
        foreach ($chosen as $uid) {
            $roles = $byId[$uid]['roles'] ?? [];
            $role = $uid === $project['lead'] ? 'lead' : (in_array('viewer', $roles, true) ? 'viewer' : 'developer');
            $members[$uid] = $role;
            $rows[] = ['project_id' => $project['id'], 'user_id' => $uid, 'project_role_id' => $this->projectRoles[$role], 'added_by' => $project['lead'], 'created_at' => $project['created'], 'updated_at' => $project['created']];
        }
        $project['members'] = $members;
        $project['doers'] = array_values(array_filter(array_keys($members), fn ($u) => $members[$u] !== 'viewer'));
        $project['member_rows'] = $rows;
    }

    /** @param array<string, mixed> $project */
    private function statuses(array &$project): void
    {
        $ids = [];
        $rows = [];
        $pos = 0;
        foreach (self::BOARD_STATUSES[$project['board']] as $slug) {
            [$name, $category, , $color, $done] = self::STATUSES[$slug];
            $id = $this->bulk->id('task_statuses');
            $ids[$slug] = $id;
            $rows[] = [
                'id' => $id, 'project_id' => $project['id'], 'name' => $name, 'slug' => $slug, 'category' => $category, 'position' => ++$pos,
                'color' => $color, 'is_default' => $slug === 'to-do', 'is_done' => $done, 'created_at' => $project['created'], 'updated_at' => $project['created'],
                'wip_limit' => $slug === 'in-progress' && $project['board'] === 'kanban' && Rng::chance(0.3) ? 25 : null,
            ];
        }
        $project['status_ids'] = $ids;
        $project['status_rows'] = $rows;
    }

    /** @param array<string, mixed> $project */
    private function components(array &$project): void
    {
        $rows = [];
        $ids = [];
        foreach (Rng::sample(['API', 'Web App', 'Mobile', 'Data Pipeline', 'Infrastructure', 'Design System', 'Billing', 'Integrations'], mt_rand(2, 4)) as $name) {
            $id = $this->bulk->id('project_components');
            $ids[] = $id;
            $rows[] = ['id' => $id, 'project_id' => $project['id'], 'name' => $name, 'description' => "$name work for {$project['name']}", 'lead_user_id' => Rng::pick($project['doers'] ?: [$project['lead']]), 'created_at' => $project['created'], 'updated_at' => $project['created']];
        }
        $project['component_ids'] = $ids;
        $project['component_rows'] = $rows;
    }

    /** @param array<string, mixed> $project */
    private function versions(array &$project): void
    {
        $tl = $this->run->tl;
        $rows = [];
        $versions = [];
        $count = mt_rand(2, 3);
        for ($v = 1; $v <= $count; $v++) {
            $id = $this->bulk->id('project_versions');
            $release = $project['start']->addDays(45 * $v + mt_rand(0, 12));
            $released = $release->lt($tl->end);
            $versions[] = ['id' => $id, 'release' => $release, 'released' => $released];
            $rows[] = ['id' => $id, 'project_id' => $project['id'], 'name' => "v{$v}.0", 'description' => "Release {$v}.0 of {$project['name']}", 'release_date' => $release->toDateString(), 'released' => $released, 'archived' => false, 'created_at' => $project['created'], 'updated_at' => $project['created']];
        }
        $project['versions'] = $versions;
        $project['version_rows'] = $rows;
    }

    // ------------------------------------------------------------------ tasks + everything derived from them

    /** @param array<string, mixed> $project @param array<string, mixed> $workspace */
    private function projectWork(array &$project, array $workspace): void
    {
        $run = $this->run;
        $tl = $run->tl;
        $tasks = $this->buildTasks($project, $workspace);
        $this->assignSprints($project, $tasks);

        // ---- core task rows
        $statusIds = $project['status_ids'];
        $positions = [];
        $rows = [];
        $lastSeq = 0;
        foreach ($tasks as $t) {
            $positions[$t['status']] = ($positions[$t['status']] ?? 0) + 1;
            $lastSeq = max($lastSeq, $t['seq']);
            $rows[] = [
                'id' => $t['id'], 'workspace_id' => $project['workspace_id'], 'project_id' => $project['id'], 'created_by' => $t['reporter'], 'reporter_id' => $t['reporter'],
                'assignee_id' => $t['assignee'], 'parent_id' => $t['parent'], 'status_id' => $statusIds[$t['status']], 'priority_id' => $this->priorities[$t['priority']],
                'key' => $t['key'], 'sequence' => $t['seq'], 'title' => $t['title'], 'description' => $t['description'],
                'due_date' => $t['due']?->toDateString(), 'estimate_minutes' => $t['estimate'], 'position' => $positions[$t['status']],
                'completed_at' => $t['completed'], 'created_at' => $t['created'], 'updated_at' => $t['updated'],
                'start_date' => $t['start']?->toDateString(), 'story_points' => $t['points'], 'issue_type_id' => $this->issueTypes[$t['type']],
                'version_id' => $t['version'], 'sprint_id' => $t['sprint'], 'epic_id' => $t['epic'],
            ];
        }
        $this->sprintRows($project, $tasks); // sprints first: tasks.sprint_id references them
        $this->bulk->insert('tasks', $rows);
        $this->sprintEvents($project, $tasks);
        DB::table('projects')->where('id', $project['id'])->update(['last_task_sequence' => $lastSeq]);

        $this->history($project, $tasks);
        $this->labelsAndComponents($project, $workspace, $tasks);
        $this->commentsAndActivity($project, $tasks);
        if ($run->module('time_tracking')) {
            $this->workLogs($project, $tasks);
        }
        $this->watchersChecklistsViews($project, $tasks);
        $this->dependencies($project, $tasks);
        $this->notifications($project, $tasks);
        if ($run->module('automation')) {
            $this->automation($project, $workspace, $tasks);
        }
        $project['task_ids'] = array_column($tasks, 'id');
    }

    /**
     * @param  array<string, mixed>  $project
     * @param  array<string, mixed>  $workspace
     * @return list<array<string, mixed>>
     */
    private function buildTasks(array $project, array $workspace): array
    {
        $tl = $this->run->tl;
        $n = $project['task_count'];
        $start = $project['start'];
        $end = $tl->end;
        $spanDays = max(1.0, $start->diffInHours($end, true) / 24);
        $doers = $project['doers'] ?: [$project['lead']];
        $everyone = array_keys($project['members']);
        $first = $this->bulk->reserve('tasks', $n);
        $versionIds = $project['versions'];
        $hasEpics = $project['board'] !== 'basic';
        $types = $project['board'] === 'basic' ? ['task' => 70, 'subtask' => 30] : ['task' => 36, 'bug' => 22, 'story' => 24, 'subtask' => 18];

        // creation times: ascending so sequence order == time order
        $stamps = [];
        for ($i = 0; $i < $n; $i++) {
            $stamps[] = Rng::between($start, $end->subHours(2), 1.25)->getTimestamp();
        }
        sort($stamps);

        $tasks = [];
        $epics = [];
        $openParents = [];
        for ($i = 0; $i < $n; $i++) {
            $seq = $i + 1;
            $created = CarbonImmutable::createFromTimestamp($stamps[$i], $start->getTimezone());
            $type = $hasEpics && $i < 3 ? 'epic' : (string) Rng::weighted($types);
            if ($type === 'subtask' && $openParents === []) {
                $type = 'task';
            }

            $parent = null;
            if ($type === 'subtask') {
                for ($try = 0; $try < 8 && $parent === null; $try++) {
                    $cand = $openParents[array_rand($openParents)];
                    if ($tasks[$cand]['completed'] === null || $tasks[$cand]['completed']->gt($created->addDay())) {
                        $parent = $cand;
                    }
                }
                if ($parent === null) {
                    $type = 'task';
                }
            }

            $ageFrac = min(1.0, max(0.0, $created->diffInHours($end, true) / 24 / $spanDays));
            $status = $this->finalStatus($type, $ageFrac, $project, $created, $end);
            if ($parent !== null && $tasks[$parent]['completed'] !== null) {
                $status = 'done';
            }

            $assignee = Rng::chance(0.9) ? Rng::pick($doers) : null;
            $reporter = Rng::chance(0.6) ? Rng::pick($project['members'] ? array_keys(array_filter($project['members'], fn ($r) => $r !== 'viewer')) ?: $everyone : $everyone) : $project['lead'];
            $points = in_array($type, ['story', 'task', 'bug'], true) ? Rng::pick([1, 2, 3, 5, 8, 13]) : null;
            $estimate = $points !== null && Rng::chance(0.75) ? $points * 90 : null;

            // lifecycle
            $completed = null;
            $startedAt = null;
            if (in_array($status, ['in-progress', 'in-review', 'done'], true)) {
                $room = max(2.0, $created->diffInHours($end, true) - 2);
                $lead = min($room, 24 * (1 + (mt_rand() / mt_getrandmax()) ** 2 * 34));
                $startedAt = $created->addMinutes((int) min($room * 60 * 0.5, mt_rand(20, 60 * 24 * 5)));
                if ($status === 'done') {
                    $completed = $created->addMinutes((int) max(90, $lead * 60));
                    if ($completed->gt($end)) {
                        $completed = $end->subMinutes(mt_rand(5, 120));
                    }
                    if ($completed->lte($startedAt)) {
                        $startedAt = $created->addMinutes(max(1, (int) ($created->diffInMinutes($completed, true) / 3)));
                    }
                    if ($parent !== null && $tasks[$parent]['completed'] !== null && $completed->gt($tasks[$parent]['completed'])) {
                        $completed = $tasks[$parent]['completed']->subMinutes(mt_rand(5, 600));
                        if ($completed->lte($created)) {
                            $completed = $created->addMinutes(30);
                            $startedAt = $created->addMinutes(10);
                        }
                    }
                }
            }
            if ($type === 'epic') {
                $status = 'in-progress';
                $completed = null;
                $startedAt = $created->addHour();
                $epics[] = $i;
            }

            $dueBase = $completed ?? $created;
            $due = Rng::chance(0.78) ? $dueBase->addDays($completed ? mt_rand(-2, 6) : mt_rand(5, 45))->startOfDay() : null;
            $version = Rng::chance(0.4) ? Rng::pick($versionIds)['id'] : null;
            $epic = null;
            if (in_array($type, ['story', 'task', 'bug'], true) && $epics !== [] && Rng::chance(0.45)) {
                $epic = $first + Rng::pick($epics);
            }

            $titleVerb = $type === 'bug' ? Rng::pick(['Fix', 'Investigate', 'Resolve']) : Rng::pick(NameBank::TASK_VERBS);
            $context = Rng::pick(NameBank::TASK_CONTEXTS);
            $title = $type === 'epic'
                ? Rng::pick(NameBank::PROJECT_AREAS).' '.Rng::pick(['foundations', 'improvements', 'hardening', 'expansion'])
                : trim($titleVerb.' '.Rng::pick(NameBank::TASK_OBJECTS).' '.($context !== '' ? sprintf($context, Rng::pick(['product', 'support', 'finance', 'operations'])) : ''));

            $tasks[$i] = [
                'id' => $first + $i, 'seq' => $seq, 'key' => $project['key'].'-'.$seq, 'title' => $title, 'type' => $type,
                'description' => $this->description($type, $title), 'created' => $created, 'status' => $status, 'completed' => $completed,
                'started' => $startedAt, 'assignee' => $type === 'epic' ? null : $assignee, 'reporter' => $reporter, 'parent' => $parent === null ? null : $tasks[$parent]['id'],
                'parent_i' => $parent, 'epic' => $epic, 'points' => $points, 'estimate' => $estimate, 'priority' => (string) Rng::weighted(['medium' => 45, 'high' => 22, 'low' => 20, 'highest' => 5, 'lowest' => 8]),
                'due' => $due, 'start' => $startedAt ?? $created, 'version' => $version, 'sprint' => null,
                'updated' => $completed ?? ($startedAt ?? $created),
            ];
            if (! in_array($type, ['subtask', 'epic'], true)) {
                $openParents[] = $i;
            }
        }

        return $tasks;
    }

    /** @param array<string, mixed> $project */
    private function finalStatus(string $type, float $ageFrac, array $project, CarbonImmutable $created, CarbonImmutable $end): string
    {
        if ($created->diffInHours($end, true) < 30) {
            $young = (string) Rng::weighted(['backlog' => 40, 'to-do' => 45, 'in-progress' => 15]);

            return $project['board'] === 'basic' && $young === 'backlog' ? 'to-do' : $young;
        }
        $span = max(1.0, $project['start']->diffInDays($end, true));
        $done = $project['archived'] ? 0.95 : (0.08 + 0.80 * $ageFrac ** 0.8) * min(1.0, 0.3 + $span / 80);
        $roll = mt_rand() / mt_getrandmax();
        if ($roll < $done) {
            return 'done';
        }
        $rest = (string) Rng::weighted(['backlog' => 18, 'to-do' => 38, 'in-progress' => 28, 'in-review' => 16]);

        return $project['board'] === 'basic' ? ($rest === 'backlog' ? 'to-do' : ($rest === 'in-review' ? 'in-progress' : $rest)) : $rest;
    }

    private function description(string $type, string $title): string
    {
        return match ($type) {
            'bug' => "<p>$title. Steps to reproduce: open the affected screen, perform the action and observe the unexpected result.</p><p>Expected: the action completes without error. Actual: inconsistent behaviour reported by users.</p>",
            'story' => "<p>As a user I want to be able to do this so that my daily workflow is faster.</p><p>Acceptance: $title works for all roles, is covered by tests and documented.</p>",
            'epic' => "<p>$title — umbrella for the related stories, tasks and bugs in this release train.</p>",
            'subtask' => "<p>Sub-step of the parent item: $title.</p>",
            default => "<p>$title. Scope agreed in planning; coordinate with the owning team and update the ticket as work progresses.</p>",
        };
    }

    // ------------------------------------------------------------------ sprints

    /** @param array<string, mixed> $project @param list<array<string, mixed>> $tasks */
    private function assignSprints(array &$project, array &$tasks): void
    {
        $project['sprints'] = [];
        if ($project['board'] !== 'scrum' || ! $this->run->module('sprints')) {
            return;
        }
        $tl = $this->run->tl;
        $length = $tl->days() < 30 ? 7 : 14;
        $spanDays = (int) $project['start']->startOfDay()->diffInDays($tl->end, true);
        $count = min(14, intdiv($spanDays, $length) + 1);
        // the last sprint contains the end date; earlier ones step back from it
        $lastStart = $tl->end->startOfDay()->subDays(mt_rand(0, $length - 2));
        $sprints = [];
        for ($k = 0; $k < $count; $k++) {
            $sStart = $lastStart->subDays(($count - 1 - $k) * $length);
            $sEnd = $sStart->addDays($length)->subSecond();
            $status = $k === $count - 1 && ! $project['archived'] ? 'active' : 'completed';
            $sprints[] = ['id' => $this->bulk->id('sprints'), 'n' => $k + 1, 'start' => $sStart, 'end' => $sEnd, 'status' => $status];
        }
        if (! $project['archived']) {
            $planned = $lastStart->addDays($length);
            $sprints[] = ['id' => $this->bulk->id('sprints'), 'n' => $count + 1, 'start' => $planned, 'end' => $planned->addDays($length)->subSecond(), 'status' => 'planned'];
        }

        // keep the most recent 14 so a long project does not need 40 sprints
        foreach ($tasks as $i => &$t) {
            if (in_array($t['type'], ['epic', 'subtask'], true)) {
                continue;
            }
            $sprint = null;
            if ($t['status'] === 'done') {
                foreach ($sprints as $s) {
                    if ($s['status'] !== 'planned' && $t['completed']->between($s['start'], $s['end'])) {
                        $sprint = $s['id'];
                    }
                }
            } elseif ($t['status'] !== 'backlog') {
                foreach ($sprints as $s) {
                    if ($s['status'] === 'active') {
                        $sprint = $s['id'];
                    }
                }
                if ($sprint !== null && Rng::chance(0.15)) {
                    $sprint = null; // carried work not yet pulled into the sprint
                }
            } elseif (Rng::chance(0.25)) {
                foreach ($sprints as $s) {
                    if ($s['status'] === 'planned') {
                        $sprint = $s['id'];
                    }
                }
            }
            $t['sprint'] = $sprint;
        }
        unset($t);
        $project['sprints'] = $sprints;
    }

    /** @param array<string, mixed> $project @param list<array<string, mixed>> $tasks */
    private function sprintRows(array $project, array $tasks): void
    {
        $rows = [];
        $names = ['Aurora', 'Beacon', 'Cascade', 'Delta', 'Ember', 'Falcon', 'Glacier', 'Harbor', 'Ion', 'Juniper', 'Keystone', 'Lantern', 'Monarch', 'Nimbus', 'Orbit'];
        $bySprint = [];
        foreach ($tasks as $t) {
            if ($t['sprint'] !== null) {
                $bySprint[$t['sprint']][] = $t;
            }
        }
        foreach ($project['sprints'] as $s) {
            $committed = 0;
            $completedPts = 0;
            foreach ($bySprint[$s['id']] ?? [] as $t) {
                $p = (int) ($t['points'] ?? 0);
                if ($t['created']->lte($s['start'])) {
                    $committed += $p;
                }
                if ($t['status'] === 'done' && $t['completed']->lte($s['end'])) {
                    $completedPts += $p;
                }
            }
            $rows[] = [
                'id' => $s['id'], 'project_id' => $project['id'], 'name' => ($names[($s['n'] - 1) % count($names)]).' '.$s['n'],
                'goal' => 'Deliver the committed stories for '.$project['name'].' and keep the bug count flat.', 'status' => $s['status'],
                'start_date' => $s['start']->toDateString(), 'end_date' => $s['end']->toDateString(),
                'started_at' => $s['status'] === 'planned' ? null : $s['start'], 'completed_at' => $s['status'] === 'completed' ? $s['end'] : null,
                'committed_points' => $s['status'] === 'planned' ? null : $committed, 'completed_points' => $s['status'] === 'completed' ? $completedPts : null,
                'created_by' => $project['lead'], 'created_at' => $s['start']->subDays(2), 'updated_at' => $s['end'],
            ];
        }
        $this->bulk->insert('sprints', $rows);
    }

    /** @param array<string, mixed> $project @param list<array<string, mixed>> $tasks */
    private function sprintEvents(array $project, array $tasks): void
    {
        $starts = [];
        foreach ($project['sprints'] as $s) {
            $starts[$s['id']] = $s['start'];
        }
        $events = [];
        foreach ($tasks as $t) {
            if ($t['sprint'] === null) {
                continue;
            }
            $start = $starts[$t['sprint']];
            $events[] = ['sprint_id' => $t['sprint'], 'task_id' => $t['id'], 'type' => 'added', 'points' => $t['points'], 'at' => $t['created']->gt($start) ? $t['created'] : $start];
        }
        $this->bulk->insert('sprint_task_events', $events);
    }

    // ------------------------------------------------------------------ derived rows

    /** @param array<string, mixed> $project @param list<array<string, mixed>> $tasks */
    private function history(array $project, array $tasks): void
    {
        $ids = $project['status_ids'];
        $rows = [];
        foreach ($tasks as $t) {
            $firstStatus = $project['board'] === 'basic' ? 'to-do' : ($t['status'] === 'backlog' ? 'backlog' : (Rng::chance(0.5) ? 'backlog' : 'to-do'));
            $path = [$firstStatus];
            if ($t['status'] !== $firstStatus) {
                if (in_array($t['status'], ['in-progress', 'in-review', 'done'], true) && isset($ids['to-do']) && $firstStatus === 'backlog') {
                    $path[] = 'to-do';
                }
                foreach (['in-progress', 'in-review', 'done'] as $step) {
                    if (! isset($ids[$step])) {
                        continue;
                    }
                    $rank = array_search($step, ['in-progress', 'in-review', 'done'], true);
                    $targetRank = array_search($t['status'], ['in-progress', 'in-review', 'done'], true);
                    if ($targetRank !== false && $rank <= $targetRank && ($step !== 'in-review' || $t['status'] !== 'done' || Rng::chance(0.7))) {
                        $path[] = $step;
                    }
                }
                if (! in_array($t['status'], $path, true)) {
                    $path[] = $t['status'];
                }
            }
            $path = array_values(array_unique($path));
            $t0 = $t['created'];
            $t1 = $t['completed'] ?? $t['started'] ?? $t0;
            $steps = count($path);
            $prev = null;
            foreach ($path as $k => $slug) {
                $at = $k === 0 ? $t0 : $t0->addSeconds((int) ($t0->diffInSeconds($t1, true) * $k / max(1, $steps - 1)));
                if ($k === $steps - 1 && $steps > 1) {
                    $at = $t['completed'] ?? $t1;
                }
                $rows[] = ['task_id' => $t['id'], 'from_status_id' => $prev === null ? null : $ids[$prev], 'to_status_id' => $ids[$slug], 'user_id' => $t['assignee'] ?? $t['reporter'], 'changed_at' => $at];
                $prev = $slug;
            }
        }
        $this->bulk->insert('task_status_history', $rows);
        $this->historyActivities = $rows;
    }

    /** @var list<array<string, mixed>> */
    private array $historyActivities = [];

    /** @param array<string, mixed> $project @param array<string, mixed> $workspace @param list<array<string, mixed>> $tasks */
    private function labelsAndComponents(array $project, array $workspace, array $tasks): void
    {
        $labelIds = array_values($workspace['labels']);
        $labelRows = [];
        $componentRows = [];
        foreach ($tasks as $t) {
            if ($t['type'] !== 'epic' && Rng::chance(0.6)) {
                foreach (array_unique(Rng::sample($labelIds, mt_rand(1, 2))) as $l) {
                    $labelRows[] = ['task_id' => $t['id'], 'label_id' => $l];
                }
            }
            if ($t['type'] !== 'epic' && Rng::chance(0.5)) {
                foreach (array_unique(Rng::sample($project['component_ids'], mt_rand(1, min(2, count($project['component_ids']))))) as $c) {
                    $componentRows[] = ['task_id' => $t['id'], 'component_id' => $c];
                }
            }
        }
        $this->bulk->insert('task_label', $labelRows);
        $this->bulk->insert('task_component', $componentRows);
    }

    /** @param array<string, mixed> $project @param list<array<string, mixed>> $tasks */
    private function commentsAndActivity(array $project, array $tasks): void
    {
        $end = $this->run->tl->end;
        $members = array_keys($project['members']);
        $commenters = $project['doers'] ?: $members;
        $comments = [];
        $activities = [];
        $statusNames = [];
        foreach ($project['status_ids'] as $slug => $id) {
            $statusNames[$id] = self::STATUSES[$slug][0];
        }
        $taskById = [];
        foreach ($tasks as $t) {
            $taskById[$t['id']] = $t;
            $activities[] = $this->activity($t['reporter'], $t['id'], 'task.created', ['key' => $t['key'], 'title' => $t['title']], $t['created']);
        }
        // moves, from the recorded history (rows[0] of each task is its creation, not a move)
        $seen = [];
        foreach ($this->historyActivities as $h) {
            if ($h['from_status_id'] === null) {
                continue;
            }
            $t = $taskById[$h['task_id']];
            $activities[] = $this->activity($h['user_id'], $t['id'], 'task.moved', ['from_status' => $statusNames[$h['from_status_id']], 'to_status' => $statusNames[$h['to_status_id']], 'key' => $t['key'], 'to_is_done' => $h['to_status_id'] === ($project['status_ids']['done'] ?? 0)], $h['changed_at']);
        }

        foreach ($tasks as $t) {
            $count = (int) Rng::weighted([0 => $t['status'] === 'done' ? 22 : 45, 1 => 28, 2 => 22, 3 => 12, 4 => 6]);
            if ($count === 0) {
                continue;
            }
            $until = $t['completed'] ?? $end;
            $topLevel = [];
            $when = $t['created'];
            for ($c = 0; $c < $count; $c++) {
                $when = $when->addMinutes(mt_rand(20, 60 * 36));
                if ($when->gt($until)) {
                    break;
                }
                $id = $this->bulk->id('comments');
                $user = Rng::chance(0.65) ? ($t['assignee'] ?? Rng::pick($commenters)) : Rng::pick($commenters);
                $parent = $topLevel !== [] && Rng::chance(0.25) ? Rng::pick($topLevel) : null;
                $text = Rng::pick(NameBank::COMMENTS);
                $comments[] = ['id' => $id, 'task_id' => $t['id'], 'user_id' => $user, 'parent_id' => $parent, 'comment' => $text, 'edited_at' => null, 'deleted_at' => null, 'created_at' => $when, 'updated_at' => $when];
                if ($parent === null) {
                    $topLevel[] = $id;
                }
                $activities[] = $this->activity($user, $t['id'], 'task.commented', ['comment_id' => $id, 'snippet' => Str::limit($text, 60)], $when);
            }
        }
        $this->bulk->insert('comments', $comments);
        $this->bulk->insert('activities', $activities);
    }

    /** @return array<string, mixed> */
    private function activity(?int $actor, int $taskId, string $action, array $data, $at): array
    {
        return ['actor_user_id' => $actor, 'subject_type' => 'App\\Models\\Task', 'subject_id' => $taskId, 'action' => $action, 'data' => json_encode($data), 'ip_address' => null, 'created_at' => $at, 'request_id' => null, 'impersonator_id' => null];
    }

    /** @param array<string, mixed> $project @param list<array<string, mixed>> $tasks */
    private function workLogs(array $project, array $tasks): void
    {
        $tl = $this->run->tl;
        $lastDay = $tl->end->subDay()->startOfDay();
        $rows = [];
        $descriptions = ['Implementation work', 'Code review and fixes', 'Investigation and debugging', 'Pairing session', 'Testing and verification', 'Design discussion and spec updates', 'Deployment and monitoring', 'Documentation'];
        foreach ($tasks as $t) {
            if (! in_array($t['status'], ['in-progress', 'in-review', 'done'], true) || $t['assignee'] === null || ! Rng::chance(0.7)) {
                continue;
            }
            $from = $t['started'] ?? $t['created'];
            $to = $t['completed'] ?? $tl->end;
            $fromDay = $from->startOfDay();
            $toDay = $to->startOfDay()->gt($lastDay) ? $lastDay : $to->startOfDay();
            if ($toDay->lt($fromDay)) {
                continue;
            }
            $days = (int) $fromDay->diffInDays($toDay, true);
            $target = $t['estimate'] ?? mt_rand(90, 600);
            $logs = mt_rand(1, min(4, $days + 1));
            for ($l = 0; $l < $logs; $l++) {
                $user = Rng::chance(0.9) ? $t['assignee'] : Rng::pick($project['doers']);
                $day = $fromDay->addDays($days === 0 ? 0 : mt_rand(0, $days));
                if ($day->isWeekend()) {
                    $day = $day->previousWeekday();
                    if ($day->lt($fromDay)) {
                        $day = $day->addWeekdays(1);
                    }
                    if ($day->gt($toDay) || $day->gt($lastDay)) {
                        continue;
                    }
                }
                $dur = (int) max(15, round(($target / $logs) * (0.6 + 0.8 * mt_rand() / mt_getrandmax()) / 15) * 15);
                $cursorKey = $user.'|'.$day->toDateString();
                $startMin = $this->dayCursor[$cursorKey] ?? 9 * 60 + mt_rand(0, 3) * 15;
                if ($day->equalTo($fromDay)) {
                    $startMin = max($startMin, (int) (ceil(($from->hour * 60 + $from->minute + 5) / 15) * 15)); // not before the work began
                }
                if ($startMin + $dur > 20 * 60) {
                    continue; // that day is full for this person
                }
                $startedAt = $day->addMinutes($startMin);
                if ($startedAt->addMinutes($dur)->gt($to)) {
                    continue; // would run past the task's completion / the end of the data window
                }
                $this->dayCursor[$cursorKey] = $startMin + $dur + 15;
                $billable = $project['billable'] && Rng::chance(0.85);
                $rows[] = ['task_id' => $t['id'], 'user_id' => $user, 'started_at' => $startedAt, 'ended_at' => $startedAt->addMinutes($dur), 'duration_minutes' => $dur, 'description' => Rng::pick($descriptions).' — '.$t['key'], 'created_at' => $startedAt->addMinutes($dur), 'updated_at' => $startedAt->addMinutes($dur), 'billable' => $billable];

                $week = $day->startOfWeek()->toDateString();
                $wk = $user.'|'.$week;
                $this->weeks[$wk] ??= ['minutes' => 0, 'billable' => 0, 'lead' => $project['lead']];
                $this->weeks[$wk]['minutes'] += $dur;
                $this->weeks[$wk]['billable'] += $billable ? $dur : 0;
            }
        }
        $this->bulk->insert('work_logs', $rows);
    }

    private function timesheets(): void
    {
        if (! $this->run->module('timesheets') || $this->weeks === []) {
            return;
        }
        $tl = $this->run->tl;
        $rows = [];
        foreach ($this->weeks as $key => $w) {
            [$user, $week] = explode('|', $key);
            $weekStart = CarbonImmutable::parse($week);
            $weekEnd = $weekStart->addDays(6)->setTime(18, 0);
            $age = (int) $weekEnd->diffInDays($tl->end, false);
            $status = $age > 14 ? 'approved' : ($age > 6 ? 'submitted' : 'open');
            if ($status === 'approved' && Rng::chance(0.05)) {
                $status = 'rejected';
            }
            $decidedAt = in_array($status, ['approved', 'rejected'], true) ? $weekEnd->addDays(mt_rand(1, 3)) : null;
            $rows[] = [
                'user_id' => (int) $user, 'week_start' => $weekStart->toDateString(), 'status' => $status, 'total_minutes' => $w['minutes'], 'billable_minutes' => $w['billable'],
                'submitted_at' => $status === 'open' ? null : $weekEnd->addHours(1), 'decided_by' => $decidedAt ? $w['lead'] : null, 'decided_at' => $decidedAt,
                'decision_note' => $status === 'rejected' ? 'Please split the long entries across the days they were worked.' : null, 'created_at' => $weekStart, 'updated_at' => $decidedAt ?? $weekEnd,
            ];
        }
        // one timesheet per (user, week)
        $this->bulk->insert('timesheets', $rows);
    }

    /** @param array<string, mixed> $project @param list<array<string, mixed>> $tasks */
    private function watchersChecklistsViews(array $project, array $tasks): void
    {
        $tl = $this->run->tl;
        $members = array_keys($project['members']);
        $watchers = [];
        $check = [];
        $views = [];
        foreach ($tasks as $t) {
            if (Rng::chance(0.25)) {
                foreach (array_unique(Rng::sample($members, mt_rand(1, 2))) as $u) {
                    $watchers[] = ['task_id' => $t['id'], 'user_id' => $u, 'created_at' => $t['created']->addHours(mt_rand(1, 48))->min($tl->end)];
                }
            }
            if ($t['type'] !== 'epic' && Rng::chance(0.15)) {
                $items = mt_rand(2, 5);
                for ($i = 0; $i < $items; $i++) {
                    $done = $t['status'] === 'done' || ($t['status'] !== 'backlog' && $i < $items - 2 && Rng::chance(0.5));
                    $at = $done ? ($t['completed'] ?? $t['started'] ?? $t['created'])->subMinutes(mt_rand(10, 600)) : null;
                    if ($at !== null && $at->lt($t['created'])) {
                        $at = $t['created']->addMinutes(5);
                    }
                    $check[] = ['task_id' => $t['id'], 'title' => Rng::pick(['Confirm requirements', 'Write the change', 'Add tests', 'Update documentation', 'Get review', 'Verify on staging', 'Notify stakeholders']), 'is_done' => $done, 'position' => $i, 'completed_at' => $at, 'completed_by' => $done ? ($t['assignee'] ?? $project['lead']) : null, 'created_at' => $t['created'], 'updated_at' => $at ?? $t['created']];
                }
            }
            if (Rng::chance(0.12)) {
                foreach (array_unique(array_filter([$t['assignee'], $t['reporter']])) as $u) {
                    $views[] = ['task_id' => $t['id'], 'user_id' => $u, 'last_seen_at' => Rng::between($t['created']->addHour(), $t['completed'] ?? $tl->end)];
                }
            }
        }
        $this->bulk->insert('task_watchers', $watchers);
        $this->bulk->insert('task_checklist_items', $check);
        $this->bulk->insert('task_views', $views);
    }

    /** @param array<string, mixed> $project @param list<array<string, mixed>> $tasks */
    private function dependencies(array $project, array $tasks): void
    {
        $rows = [];
        $n = count($tasks);
        foreach ($tasks as $i => $t) {
            if ($i < 8 || $t['type'] === 'epic' || ! Rng::chance(0.09)) {
                continue;
            }
            $b = $tasks[mt_rand(0, $i - 1)];
            if ($b['type'] === 'epic' || $b['id'] === $t['parent'] || $t['id'] === $b['parent']) {
                continue;
            }
            // a blocker must be finished before the dependent can be: done dependent => done blocker, finished earlier
            if ($t['status'] === 'done' && ($b['status'] !== 'done' || $b['completed']->gt($t['completed']))) {
                continue;
            }
            $rows[$t['id'].':'.$b['id']] = ['task_id' => $t['id'], 'depends_on_task_id' => $b['id'], 'type' => 'blocks', 'created_at' => $t['created'], 'updated_at' => $t['created']];
        }
        $this->bulk->insert('task_dependencies', array_values($rows));
    }

    /** @param array<string, mixed> $project @param list<array<string, mixed>> $tasks */
    private function notifications(array $project, array $tasks): void
    {
        $tl = $this->run->tl;
        $cut = $tl->end->subDays(14);
        $rows = [];
        foreach ($tasks as $t) {
            if ($t['assignee'] === null || $t['reporter'] === $t['assignee'] || $t['created']->lt($cut)) {
                continue;
            }
            $read = Rng::chance(0.6) ? $t['created']->addHours(mt_rand(1, 30)) : null;
            $rows[] = [
                'user_id' => $t['assignee'], 'actor_id' => $t['reporter'], 'type' => 'task.assigned',
                'data' => json_encode(['task_id' => $t['id'], 'key' => $t['key'], 'title' => $t['title'], 'project_id' => $project['id'], 'project_name' => $project['name'], 'workspace_id' => $project['workspace_id']]),
                'read_at' => $read && $read->lte($tl->end) ? $read : null, 'created_at' => $t['created'], 'updated_at' => $t['created'], 'dedupe_key' => null,
            ];
        }
        $this->bulk->insert('notifications', $rows);
    }

    /** @param array<string, mixed> $project @param array<string, mixed> $workspace @param list<array<string, mixed>> $tasks */
    private function automation(array $project, array $workspace, array $tasks): void
    {
        if (! Rng::chance(0.65)) {
            return;
        }
        $tl = $this->run->tl;
        $ruleRows = [];
        $runRows = [];
        $labelRows = [];
        $created = $project['created']->addDays(mt_rand(1, 5));
        $mk = function (string $name, string $trigger, array $conditions, array $actions, array $matches, ?callable $apply = null) use ($project, $created, $tl, &$ruleRows, &$runRows) {
            $id = $this->bulk->id('automation_rules');
            $matches = array_slice($matches, 0, 60);
            $last = null;
            foreach ($matches as $t) {
                $at = ($t['completed'] ?? $t['created'])->addSeconds(mt_rand(1, 30));
                if ($at->lt($created) || $at->gt($tl->end)) {
                    continue;
                }
                $runRows[] = ['rule_id' => $id, 'event_uuid' => (string) Str::uuid(), 'task_id' => $t['id'], 'status' => 'success', 'summary' => $name, 'created_at' => $at, 'updated_at' => $at];
                $last = $last === null || $at->gt($last) ? $at : $last;
            }
            $ruleRows[] = ['id' => $id, 'project_id' => $project['id'], 'name' => $name, 'is_active' => true, 'trigger' => $trigger, 'conditions' => json_encode($conditions), 'actions' => json_encode($actions), 'created_by' => $project['lead'], 'run_count' => count(array_filter($runRows, fn ($r) => $r['rule_id'] === $id)), 'last_run_at' => $last, 'created_at' => $created, 'updated_at' => $last ?? $created];
        };

        $high = [$this->priorities['highest'], $this->priorities['high']];
        $urgentBugs = array_values(array_filter($tasks, fn ($t) => $t['type'] === 'bug' && in_array($this->priorities[$t['priority']], $high, true)));
        $mk('Notify the lead about urgent bugs', 'task.created', [['field' => 'issue_type', 'op' => 'is', 'value' => $this->issueTypes['bug']], ['field' => 'priority', 'op' => 'in', 'value' => $high]], [['type' => 'notify', 'message' => 'Urgent bug {{key}}: {{title}}']], $urgentBugs);

        $done = array_values(array_filter($tasks, fn ($t) => $t['status'] === 'done'));
        $mk('Thank the assignee when work completes', 'task.completed', [], [['type' => 'add_comment', 'text' => 'Nice work — {{key}} is done.']], $done);

        if (Rng::chance(0.5)) {
            $label = $workspace['labels']['tech-debt'];
            $overdue = array_values(array_filter($tasks, fn ($t) => $t['status'] !== 'done' && $t['status'] !== 'backlog' && $t['due'] !== null && $t['due']->lt($tl->end->subDays(2))));
            $mk('Label overdue tasks as tech-debt', 'task.overdue', [['field' => 'due_date', 'op' => 'is_overdue']], [['type' => 'add_label', 'label' => $label]], $overdue);
            foreach (array_slice($overdue, 0, 60) as $t) {
                $labelRows[$t['id'].':'.$label] = ['task_id' => $t['id'], 'label_id' => $label];
            }
        }

        $this->bulk->insert('automation_rules', $ruleRows);
        $this->bulk->insert('automation_runs', $runRows);
        if ($labelRows) {
            // a task may already carry this label from the random labelling: add only the missing ones
            $existing = DB::table('task_label')->whereIn('task_id', array_column($labelRows, 'task_id'))->get(['task_id', 'label_id'])->map(fn ($r) => $r->task_id.':'.$r->label_id)->flip();
            $this->bulk->insert('task_label', array_values(array_filter($labelRows, fn ($r, $k) => ! isset($existing[$k]), ARRAY_FILTER_USE_BOTH)));
        }
    }

    /** @param list<array<string, mixed>> $projects */
    private function userSettingsAndFilters(array $projects): void
    {
        $rows = [];
        $dash = [];
        foreach ($projects as $p) {
            if (Rng::chance(0.5) && $this->run->module('saved_filters')) {
                $rows[] = ['project_id' => $p['id'], 'owner_user_id' => $p['lead'], 'name' => 'My open work', 'filters' => json_encode(['assignee' => 'me', 'status_category' => 'open']), 'jql' => null, 'is_shared' => false, 'created_at' => $p['created'], 'updated_at' => $p['created']];
                $rows[] = ['project_id' => $p['id'], 'owner_user_id' => $p['lead'], 'name' => 'High priority bugs', 'filters' => null, 'jql' => 'type = bug AND priority in (highest, high)', 'is_shared' => true, 'created_at' => $p['created'], 'updated_at' => $p['created']];
            }
            if (Rng::chance(0.3)) {
                $dash[] = ['project_id' => $p['id'], 'user_id' => null, 'widgets' => json_encode(['overview', 'status_breakdown', 'assignee_load', 'overdue_list', 'created_vs_done']), 'created_at' => $p['created'], 'updated_at' => $p['created']];
            }
        }
        $this->bulk->insert('saved_filters', $rows);
        $this->bulk->insert('project_dashboards', $dash);
    }
}
