<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P4.10: WIP limits and board swimlanes.
 *
 * A status may carry a `wip_limit` (nullable positive integer). Board
 * arrivals into the column are checked against it — create, update and
 * move all refuse a column that would exceed its limit — while a reorder
 * inside the same column is unaffected (the mover keeps its own occupancy).
 * The count is the column's OPEN top-level cards, matching the board's own
 * `open_count`, so subtasks and completed cards never trip a limit.
 *
 * Swimlanes group the board by assignee. `?swimlane=assignee` returns
 * `{swimlanes: [...]}` (assignee lanes sorted by name, the unassigned lane
 * last) instead of the flat `{statuses: [...]}`; every lane carries its own
 * columns (counts scoped to that lane) and its own open/done totals.
 */
class TaskBoardWipSwimlaneTest extends TestCase
{
    use IsolatesDatabase;

    private function admin(): User
    {
        return User::where('email', 'admin@flowsync.test')->firstOrFail();
    }

    private function tenantMember(string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'name' => ucfirst(strtok($email, '@')),
            'password' => 'password',
        ]);
        $user->roles()->attach(Role::where('slug', 'viewer')->firstOrFail());

        return $user;
    }

    private function makeWorkspace(): Workspace
    {
        $workspace = Workspace::create([
            'created_by' => $this->admin()->id,
            'name' => 'WIP Workspace',
            'slug' => 'wip-workspace',
        ]);
        $workspace->members()->attach($this->admin()->id, ['role' => 'owner', 'added_by' => $this->admin()->id]);

        return $workspace;
    }

    private function makeProject(Workspace $workspace, string $key = 'WIP'): Project
    {
        $project = Project::create([
            'workspace_id' => $workspace->id,
            'created_by' => $this->admin()->id,
            'lead_user_id' => $this->admin()->id,
            'name' => 'WIP Project',
            'key' => $key,
        ]);

        $position = 0;
        foreach (config('task_statuses.statuses') as $status) {
            TaskStatus::create([
                'project_id' => $project->id,
                'name' => $status['name'],
                'slug' => $status['slug'],
                'category' => $status['category'],
                'position' => ++$position,
                'color' => $status['color'] ?? null,
                'is_default' => $status['is_default'] ?? false,
                'is_done' => $status['is_done'] ?? false,
            ]);
        }

        $lead = ProjectRole::where('slug', 'lead')->firstOrFail();
        $project->members()->attach($this->admin()->id, ['project_role_id' => $lead->id, 'added_by' => $this->admin()->id]);

        return $project;
    }

    private function addMember(Project $project, User $user, string $roleSlug = 'developer'): void
    {
        $role = ProjectRole::where('slug', $roleSlug)->firstOrFail();
        $project->members()->attach($user->id, ['project_role_id' => $role->id, 'added_by' => $this->admin()->id]);
    }

    private function statusOf(Project $project, string $slug): TaskStatus
    {
        return $project->statuses()->where('slug', $slug)->firstOrFail();
    }

    private function addTask(Project $project, string $title, array $extra = []): Task
    {
        $project->increment('last_task_sequence');

        return Task::create(array_merge([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'created_by' => $this->admin()->id,
            'reporter_id' => $this->admin()->id,
            'assignee_id' => $this->admin()->id,
            'key' => $project->key.'-'.$project->last_task_sequence,
            'sequence' => $project->last_task_sequence,
            'title' => $title,
            'status_id' => $this->statusOf($project, 'to-do')->id,
            'position' => $project->tasks()->max('position') + 1,
        ], $extra));
    }

    public function test_wip_limit_surfaces_in_status_and_board_payloads(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->makeProject($ws);
        $this->loginAs('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/statuses", [
            'name' => 'Blocked', 'category' => 'todo', 'wip_limit' => 2,
        ])
            ->assertCreated()
            ->assertJsonPath('status.wip_limit', 2);

        $blocked = $project->statuses()->where('name', 'Blocked')->firstOrFail();
        $blockedIdx = $blocked->position - 1;

        // The status list and the board both echo it (board JSON is per-column).
        $this->getJson("/api/projects/{$project->id}/statuses")
            ->assertOk()
            ->assertJsonPath('statuses.0.wip_limit', null)
            ->assertJsonPath("statuses.{$blockedIdx}.wip_limit", 2);

        $this->addTask($project, 'T1');
        $this->getJson("/api/projects/{$project->id}/tasks?view=board")
            ->assertOk()
            ->assertJsonPath("board.statuses.{$blockedIdx}.wip_limit", 2)
            ->assertJsonPath("board.statuses.{$blockedIdx}.open_count", 0);

        $this->putJson("/api/projects/{$project->id}/statuses/{$blocked->id}", ['wip_limit' => 3])
            ->assertOk()
            ->assertJsonPath('status.wip_limit', 3);

        $this->putJson("/api/projects/{$project->id}/statuses/{$blocked->id}", ['wip_limit' => null])
            ->assertOk()
            ->assertJsonPath('status.wip_limit', null);
    }

    public function test_wip_limit_is_validated(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->makeProject($ws);
        $this->loginAs('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/statuses", [
            'name' => 'Blocked', 'category' => 'todo', 'wip_limit' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('wip_limit');
    }

    public function test_the_status_category_check_survives_the_wip_limit_alter(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->makeProject($ws);

        // `category` is an enum column, i.e. a CHECK constraint. The safest
        // way to add `wip_limit` without an sqlite table rebuild is the plain
        // add-column the migration uses; if it ever regresses to a rebuild,
        // doctrine introspection drops this CHECK and the insert below lands.
        try {
            DB::table('task_statuses')->insert([
                'project_id' => $project->id,
                'name' => 'Bogus',
                'slug' => 'bogus',
                'category' => 'spectral',
                'position' => 99,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('A made-up status category must not insert — the CHECK is gone.');
        } catch (QueryException) {
            // Expected.
        }

        // A well-formed row still inserts: the alter added a column, it did
        // not change what the table accepts.
        $id = DB::table('task_statuses')->insertGetId([
            'project_id' => $project->id,
            'name' => 'Fine',
            'slug' => 'fine',
            'category' => 'todo',
            'position' => 98,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertSame($id, DB::table('task_statuses')->where('name', 'Fine')->value('id'));
    }

    public function test_move_into_a_full_column_is_refused_and_a_reorder_is_allowed(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->makeProject($ws);
        $inProgress = $this->statusOf($project, 'in-progress');
        $inProgress->update(['wip_limit' => 2]);

        $a = $this->addTask($project, 'A', ['status_id' => $inProgress->id, 'position' => 1]);
        $b = $this->addTask($project, 'B', ['status_id' => $inProgress->id, 'position' => 2]);
        $c = $this->addTask($project, 'C');

        $this->loginAs('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks/{$c->id}/move", ['status_id' => $inProgress->id, 'index' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('errors.form.0', '"In Progress" is at its WIP limit (2). Move a card out or raise the limit.');

        // A reorder inside the same (full) column does not add occupancy.
        $this->postJson("/api/projects/{$project->id}/tasks/{$a->id}/move", ['status_id' => $inProgress->id, 'index' => 2])
            ->assertOk();

        // Freeing a slot lets the same move through.
        $b->update(['status_id' => $this->statusOf($project, 'in-review')->id]);
        $this->postJson("/api/projects/{$project->id}/tasks/{$c->id}/move", ['status_id' => $inProgress->id, 'index' => 1])
            ->assertOk();
    }

    public function test_create_and_update_into_a_full_column_are_refused(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->makeProject($ws);
        $inProgress = $this->statusOf($project, 'in-progress');
        $inProgress->update(['wip_limit' => 1]);
        $this->addTask($project, 'First', ['status_id' => $inProgress->id]);

        $this->loginAs('admin@flowsync.test');

        $this->postJson("/api/projects/{$project->id}/tasks", ['title' => 'Second', 'status_id' => $inProgress->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('form');

        // A status-change edit is held to the same rule...
        $task = $this->addTask($project, 'Elsewhere');
        $this->putJson("/api/projects/{$project->id}/tasks/{$task->id}", ['status_id' => $inProgress->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('form');

        // ...and an edit that keeps its own status is not.
        $this->putJson("/api/projects/{$project->id}/tasks/{$task->id}", ['title' => 'Renamed'])
            ->assertOk();
    }

    public function test_wip_counts_open_top_level_cards_only(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->makeProject($ws);
        $inProgress = $this->statusOf($project, 'in-progress');
        $inProgress->update(['wip_limit' => 1]);
        $inProgressIdx = $inProgress->position - 1;

        // A completed card in the column is not "work in progress".
        $this->addTask($project, 'Finished', ['status_id' => $inProgress->id, 'completed_at' => now()]);
        // A subtask rides inside its parent and never trips a column limit.
        $parent = $this->addTask($project, 'Parent', ['status_id' => $this->statusOf($project, 'to-do')->id]);
        $project->increment('last_task_sequence');
        Task::create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'created_by' => $this->admin()->id,
            'reporter_id' => $this->admin()->id,
            'assignee_id' => $this->admin()->id,
            'parent_id' => $parent->id,
            'key' => $project->key.'-'.$project->last_task_sequence,
            'sequence' => $project->last_task_sequence,
            'title' => 'Subtask',
            'status_id' => $inProgress->id,
            'position' => 1,
        ]);

        $this->loginAs('admin@flowsync.test');

        // The board counts open top-level cards only: the subtask (a child,
        // not a board card) and the completed card are both invisible to the
        // WIP count, though the completed card is still a card on the board.
        $this->getJson("/api/projects/{$project->id}/tasks?view=board")
            ->assertOk()
            ->assertJsonPath("board.statuses.{$inProgressIdx}.open_count", 0)
            ->assertJsonPath("board.statuses.{$inProgressIdx}.tasks_count", 1);

        // ...which means a move into that "full-looking" column still lands.
        $open = $this->addTask($project, 'Open', ['status_id' => $this->statusOf($project, 'backlog')->id]);
        $this->postJson("/api/projects/{$project->id}/tasks/{$open->id}/move", ['status_id' => $inProgress->id, 'index' => 1])
            ->assertOk()
            ->assertJsonPath('task.status.slug', 'in-progress');
    }

    public function test_swimlane_board_groups_by_assignee_unassigned_last(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->makeProject($ws);
        $alice = $this->tenantMember('alice@flowsync.test');
        $bob = $this->tenantMember('bob@flowsync.test');
        $this->addMember($project, $alice);
        $this->addMember($project, $bob);

        $inProgress = $this->statusOf($project, 'in-progress');
        $this->addTask($project, 'Alice open', ['assignee_id' => $alice->id, 'status_id' => $inProgress->id]);
        $this->addTask($project, 'Bob open', ['assignee_id' => $bob->id, 'status_id' => $inProgress->id]);
        $this->addTask($project, 'Unassigned open', ['assignee_id' => null, 'status_id' => $inProgress->id]);
        $this->addTask($project, 'Alice done', ['assignee_id' => $alice->id, 'status_id' => $this->statusOf($project, 'done')->id, 'completed_at' => now()]);

        $this->loginAs('admin@flowsync.test');

        $this->getJson("/api/projects/{$project->id}/tasks?view=board&swimlane=assignee")
            ->assertOk()
            ->assertJsonCount(3, 'board.swimlanes')
            ->assertJsonPath('board.swimlanes.0.assignee.name', 'Alice')
            ->assertJsonPath('board.swimlanes.1.assignee.name', 'Bob')
            ->assertJsonPath('board.swimlanes.2.assignee', null)
            ->assertJsonPath('board.swimlanes.0.totals.open', 1)
            ->assertJsonPath('board.swimlanes.0.totals.done', 1)
            ->assertJsonPath('board.swimlanes.1.totals.open', 1)
            ->assertJsonPath('board.swimlanes.2.totals.open', 1)
            ->assertJsonPath('board.totals.open', 3)
            ->assertJsonPath('board.totals.done', 1);

        // The swimlane column counts are scoped to that lane.
        $response = $this->getJson("/api/projects/{$project->id}/tasks?view=board&swimlane=assignee")
            ->assertOk()
            ->json();
        $aliceLane = collect($response['board']['swimlanes'])->firstWhere('assignee.name', 'Alice');
        $inProgressColumn = collect($aliceLane['statuses'])->firstWhere('slug', 'in-progress');
        $this->assertSame(1, $inProgressColumn['tasks_count']);
        $this->assertSame(['Alice open'], array_column($inProgressColumn['tasks'], 'title'));
    }

    public function test_flat_board_is_unchanged_and_swimlane_is_validated(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->makeProject($ws);
        $this->addTask($project, 'Plain');

        $this->loginAs('admin@flowsync.test');

        $this->getJson("/api/projects/{$project->id}/tasks?view=board")
            ->assertOk()
            ->assertJsonPath('board.statuses.1.tasks_count', 1)
            ->assertJsonMissingPath('board.swimlanes');

        $this->getJson("/api/projects/{$project->id}/tasks?view=board&swimlane=bogus")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('swimlane');
    }

    public function test_swimlane_board_respects_task_row_scope(): void
    {
        $ws = $this->makeWorkspace();
        $project = $this->makeProject($ws);
        $edward = $this->tenantMember('edward@flowsync.test');
        $reader = $this->tenantMember('reader@flowsync.test');
        $this->addMember($project, $edward);

        $ownRole = ProjectRole::create([
            'name' => 'Own Tasks Read',
            'slug' => 'own-task-read',
            'is_system' => false,
            'permissions' => ['tasks.view_own'],
        ]);
        $this->addMember($project, $reader, $ownRole->slug);

        $inProgress = $this->statusOf($project, 'in-progress');
        $this->addTask($project, 'My task', ['assignee_id' => $reader->id, 'status_id' => $inProgress->id]);
        // Another lane exists but the reader may not see its rows.
        $this->addTask($project, 'Someone else', ['assignee_id' => $edward->id, 'status_id' => $inProgress->id]);

        $this->loginAs('reader@flowsync.test');

        $response = $this->getJson("/api/projects/{$project->id}/tasks?view=board&swimlane=assignee")
            ->assertOk()
            ->assertJsonCount(1, 'board.swimlanes')
            ->assertJsonPath('board.swimlanes.0.assignee.name', 'Reader')
            ->assertJsonPath('board.swimlanes.0.statuses.2.tasks_count', 1);
        $this->assertSame(['My task'], array_column($response['board']['swimlanes'][0]['statuses'][2]['tasks'], 'title'));
    }
}
