<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\MarketingLead;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PlatformLeadReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly MarketingLead $lead) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('platform.admin')];
    }

    public function broadcastAs(): string
    {
        return 'lead.received';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->lead->id,
            'name' => $this->lead->name,
            'email' => $this->lead->email,
            'company' => $this->lead->company,
            'source' => $this->lead->source,
            'created_at' => $this->lead->created_at?->toIso8601String(),
        ];
    }
}
