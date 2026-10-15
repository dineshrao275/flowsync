<?php

namespace Tests\Feature;

use App\Models\DomainEvent;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Events\DomainEvents;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** P2.1 — the domain event bus: what is recorded, when, and that a broken consumer cannot hurt the request. */
class DomainEventBusTest extends TestCase
{
    use IsolatesDatabase;

    private array $recorded = [];

    private function project(): array
    {
        $admin = User::where('email', 'admin@flowsync.test')->firstOrFail();
        $ws = Workspace::create(['created_by' => $admin->id, 'name' => 'W', 'slug' => 'w']);
        $ws->members()->attach($admin->id, ['role' => 'owner', 'added_by' => $admin->id]);
        $project = Project::create(['workspace_id' => $ws->id, 'created_by' => $admin->id, 'lead_user_id' => $admin->id, 'name' => 'P', 'key' => 'PP']);
        $project->members()->attach($admin->id, ['project_role_id' => ProjectRole::where('slug', 'lead')->firstOrFail()->id, 'added_by' => $admin->id]);
        foreach (config('task_statuses.statuses') as $s) {
            TaskStatus::create(['project_id' => $project->id, 'name' => $s['name'], 'slug' => $s['slug'], 'category' => $s['category'], 'position' => $s['position'], 'color' => $s['color'], 'is_done' => $s['is_done'], 'is_default' => $s['is_default'] ?? false]);
        }

        return [$project, TaskStatus::where('project_id', $project->id)->where('is_done', true)->firstOrFail()];
    }

    private function login(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();
    }

    public function test_task_actions_are_recorded_as_events_with_the_actor(): void
    {
        [$project, $done] = $this->project();
        $this->login();

        $id = $this->postJson("/api/projects/{$project->id}/tasks", ['title' => 'Ship it'])->assertCreated()->json('task.id');
        $this->postJson("/api/projects/{$project->id}/tasks/{$id}/move", ['status_id' => $done->id])->assertOk();

        $types = DomainEvent::orderBy('id')->pluck('type')->all();
        $this->assertSame(['task.created', 'task.moved', 'task.completed'], $types);

        $created = DomainEvent::where('type', 'task.created')->firstOrFail();
        $this->assertSame(Task::class, $created->subject_type);
        $this->assertSame($id, $created->subject_id);
        $this->assertNotNull($created->actor_user_id);
        $this->assertSame('Ship it', $created->data['title']);
        $this->assertNotNull($created->processed_at);   // handed to the consumers
    }

    public function test_completing_through_the_edit_form_emits_task_completed_once(): void
    {
        [$project, $done] = $this->project();
        $this->login();
        $id = $this->postJson("/api/projects/{$project->id}/tasks", ['title' => 'Edit me'])->json('task.id');

        $this->putJson("/api/projects/{$project->id}/tasks/{$id}", ['status_id' => $done->id])->assertOk();
        $this->putJson("/api/projects/{$project->id}/tasks/{$id}", ['title' => 'Renamed'])->assertOk();

        $this->assertSame(1, DomainEvent::where('type', 'task.completed')->count());
    }

    public function test_a_failing_consumer_is_contained_and_the_others_still_run(): void
    {
        config(['domain_events.consumers' => [
            'broken' => ['class' => BrokenConsumer::class, 'patterns' => ['*']],
            'spy' => ['class' => SpyConsumer::class, 'patterns' => ['demo.*']],
        ]]);
        SpyConsumer::$seen = [];

        $event = app(DomainEvents::class)->record('demo.thing', null, null, ['a' => 1]);

        $this->assertSame(['demo.thing'], SpyConsumer::$seen);       // the second consumer ran
        $this->assertNotNull($event->fresh()->processed_at);          // and the event completed
    }

    public function test_patterns_decide_which_consumers_see_an_event(): void
    {
        config(['domain_events.consumers' => ['spy' => ['class' => SpyConsumer::class, 'patterns' => ['task.*']]]]);
        SpyConsumer::$seen = [];

        app(DomainEvents::class)->record('leave.approved');
        app(DomainEvents::class)->record('task.created');

        $this->assertSame(['task.created'], SpyConsumer::$seen);
    }
}

class BrokenConsumer
{
    public function handle(DomainEvent $e): void
    {
        throw new \RuntimeException('integration is down');
    }
}

class SpyConsumer
{
    public static array $seen = [];

    public function handle(DomainEvent $e): void
    {
        self::$seen[] = $e->type;
    }
}
