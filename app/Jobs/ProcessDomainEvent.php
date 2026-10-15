<?php

namespace App\Jobs;

use App\Models\DomainEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Hands one recorded event to every consumer whose pattern matches; one failing never stops another. */
class ProcessDomainEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $eventId) {}

    public function handle(): void
    {
        $event = DomainEvent::find($this->eventId);
        if (! $event || $event->processed_at) {
            return;
        }

        foreach (config('domain_events.consumers', []) as $name => $consumer) {
            if (! Str::is($consumer['patterns'] ?? ['*'], $event->type)) {
                continue;
            }

            try {
                app($consumer['class'])->handle($event);
            } catch (Throwable $e) {
                Log::error('Domain event consumer failed.', ['consumer' => $name, 'event' => $event->uuid, 'type' => $event->type, 'error' => $e->getMessage()]);
            }
        }

        $event->forceFill(['processed_at' => now()])->save();
    }
}
