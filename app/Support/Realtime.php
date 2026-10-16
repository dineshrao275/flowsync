<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one door for pushing an event to WebSocket clients.
 *
 * Broadcasting is a courtesy layered over state that is already saved: the
 * comment, task move or notification row exists whether or not the Reverb
 * server is reachable, and every screen still refetches/polls as a fallback.
 * So a failed push must never turn a successful write into a 500 — it is
 * logged (with the event class and request id from the shared log context)
 * and swallowed. All events here are `ShouldBroadcastNow`: the push happens
 * in-request in a few milliseconds instead of waiting on a queue worker, which
 * is what makes updates feel instant and keeps realtime independent of
 * worker health.
 */
final class Realtime
{
    public static function send(object $event): void
    {
        try {
            broadcast($event);
        } catch (Throwable $e) {
            Log::warning('Realtime broadcast failed: '.$e->getMessage(), [
                'event' => $event::class,
                'exception' => $e::class,
            ]);
        }
    }
}
