<?php

namespace App\Services\Events;

use App\Jobs\ProcessDomainEvent;
use App\Models\DomainEvent;
use Illuminate\Support\Str;

/**
 * The domain event bus. `record()` writes the outbox row where the change happens;
 * consumers (config/domain_events.php) get it from a queued job afterwards, so a slow
 * or broken integration can never slow down or fail the request that caused the event.
 */
class DomainEvents
{
    /** @param array<string, mixed> $data */
    public function record(string $type, ?string $subjectType = null, ?int $subjectId = null, array $data = [], ?int $actorId = null): DomainEvent
    {
        $event = DomainEvent::create([
            'uuid' => (string) Str::uuid(),
            'type' => $type,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'actor_user_id' => $actorId,
            'data' => $data ?: null,
            'occurred_at' => now(),
        ]);

        if (config('domain_events.consumers')) {
            ProcessDomainEvent::dispatch($event->id)->afterCommit();
        }

        return $event;
    }
}
