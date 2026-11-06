<?php

declare(strict_types=1);

namespace App\Services\Ai;

/** Writes server-sent events and flushes after each, so the browser sees tokens as they arrive. */
final class SseEmitter
{
    /** @param array<string,mixed> $data */
    public function send(string $event, array $data): void
    {
        echo "event: {$event}\ndata: ".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";

        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }
}
