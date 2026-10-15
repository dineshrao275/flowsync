<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Events\DomainEvents;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P2.3: task notifications are produced by the domain-event consumer. Events
 * from other emitters (the automation engine tags its own) must stay silent.
 */
class NotificationConsumerTest extends TestCase
{
    use IsolatesDatabase;

    private function task(User $assignee): Task
    {
        return Task::factory()->create(['assignee_id' => $assignee->id]);
    }

    public function test_a_created_event_that_names_the_assignee_notifies_them(): void
    {
        $actor = User::where('email', 'admin@flowsync.test')->firstOrFail();
        $assignee = User::where('email', 'editor@flowsync.test')->firstOrFail();
        $task = $this->task($assignee);

        app(DomainEvents::class)->record('task.created', Task::class, $task->id, ['assignee_id' => $assignee->id], $actor->id);

        $this->assertSame(1, UserNotification::where('user_id', $assignee->id)->where('type', 'task.assigned')->count());
    }

    public function test_an_event_without_the_opt_in_facts_stays_silent(): void
    {
        $actor = User::where('email', 'admin@flowsync.test')->firstOrFail();
        $assignee = User::where('email', 'editor@flowsync.test')->firstOrFail();
        $task = $this->task($assignee);

        app(DomainEvents::class)->record('task.created', Task::class, $task->id, ['key' => $task->key], $actor->id);
        app(DomainEvents::class)->record('task.updated', Task::class, $task->id, ['fields' => ['assignee_id']], $actor->id);

        $this->assertSame(0, UserNotification::where('user_id', $assignee->id)->count());
    }

    public function test_automation_tagged_events_never_notify(): void
    {
        $actor = User::where('email', 'admin@flowsync.test')->firstOrFail();
        $assignee = User::where('email', 'editor@flowsync.test')->firstOrFail();
        $task = $this->task($assignee);

        app(DomainEvents::class)->record(
            'task.updated', Task::class, $task->id,
            ['assignee_changed' => true, 'automation_rule_id' => 1],
            $actor->id,
        );

        $this->assertSame(0, UserNotification::where('user_id', $assignee->id)->count());
    }

    public function test_self_assignment_is_skipped(): void
    {
        $actor = User::where('email', 'admin@flowsync.test')->firstOrFail();
        $task = $this->task($actor);

        app(DomainEvents::class)->record('task.updated', Task::class, $task->id, ['assignee_changed' => true], $actor->id);

        $this->assertSame(0, UserNotification::where('user_id', $actor->id)->count());
    }
}
