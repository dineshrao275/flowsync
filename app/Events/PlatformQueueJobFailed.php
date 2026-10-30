<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class PlatformQueueJobFailed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $job,
        public readonly string $queue,
        public readonly string $error,
        public readonly ?int $tenantId = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('platform.admin')];
    }

    public function broadcastAs(): string
    {
        return 'queue.job.failed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'job' => $this->job,
            'queue' => $this->queue,
            'error' => mb_substr($this->error, 0, 300),
            'tenant_id' => $this->tenantId,
            'failed_at' => now()->toIso8601String(),
        ];
    }
}
