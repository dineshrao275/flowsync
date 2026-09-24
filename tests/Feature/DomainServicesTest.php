<?php

namespace Tests\Feature;

use App\Events\NotificationSent;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ActivityLogger;
use App\Services\KeyGenerator;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class DomainServicesTest extends TestCase
{
    use IsolatesDatabase;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::create([
            'name' => 'Eng',
            'slug' => 'eng',
        ]);

        $this->project = Project::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Backend',
            'key' => 'be',
        ]);
    }

    public function test_key_generator_produces_sequential_unique_keys(): void
    {
        $generator = app(KeyGenerator::class);

        $keys = collect([1, 2, 3])->map(fn () => $generator->nextTaskKey($this->project));

        $this->assertSame(['BE-1', 'BE-2', 'BE-3'], $keys->map(fn ($key) => $key[0])->all());
        $this->assertSame([1, 2, 3], $keys->map(fn ($key) => $key[1])->all());
        $this->assertSame(3, $this->project->fresh()->last_task_sequence);
    }

    public function test_activity_logger_records_activity(): void
    {
        $actor = User::where('email', 'admin@flowsync.test')->first();

        $activity = app(ActivityLogger::class)->log(
            subjectType: Task::class,
            subjectId: 42,
            action: 'task.created',
            data: ['title' => 'Hello'],
            actor: $actor,
        );

        $this->assertDatabaseHas('activities', [
            'id' => $activity->id,
            'actor_user_id' => $actor->id,
            'subject_id' => 42,
        ]);

        $found = app(ActivityLogger::class)->forSubject(Task::class, 42)->get();
        $this->assertCount(1, $found);
    }

    public function test_notification_service_creates_and_broadcasts(): void
    {
        Event::fake([NotificationSent::class]);

        $recipient = User::where('email', 'editor@flowsync.test')->first();
        $actor = User::where('email', 'admin@flowsync.test')->first();

        $notification = app(NotificationService::class)->notify(
            $recipient,
            'task.assigned',
            ['task_id' => 42],
            $actor,
        );

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'user_id' => $recipient->id,
            'type' => 'task.assigned',
            'read_at' => null,
        ]);

        Event::assertDispatched(NotificationSent::class, function (NotificationSent $event) use ($recipient) {
            return $event->notification->user_id === $recipient->id;
        });
    }

    public function test_notification_service_counts_and_marks_read(): void
    {
        Event::fake([NotificationSent::class]);

        $service = app(NotificationService::class);
        $recipient = User::where('email', 'editor@flowsync.test')->first();

        $service->notify($recipient, 'task.assigned', ['task_id' => 1]);
        $service->notify($recipient, 'task.mentioned', ['task_id' => 2]);

        $this->assertSame(2, $service->unreadCount($recipient));
        $this->assertSame(2, $service->markAllRead($recipient));
        $this->assertSame(0, $service->unreadCount($recipient));
    }

    public function test_database_transaction_is_rollback_safe(): void
    {
        DB::beginTransaction();

        try {
            app(KeyGenerator::class)->nextTaskKey($this->project);
            $this->assertSame(1, $this->project->fresh()->last_task_sequence);
        } finally {
            DB::rollBack();
        }

        $this->assertSame(0, $this->project->fresh()->last_task_sequence);
    }
}