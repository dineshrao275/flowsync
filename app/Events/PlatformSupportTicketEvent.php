<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\SupportTicket;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PlatformSupportTicketEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly SupportTicket $ticket,
        public readonly string $action = 'created',
        public readonly ?string $authorName = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('platform.admin')];
    }

    public function broadcastAs(): string
    {
        return 'support.ticket.alert';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->ticket->id,
            'action' => $this->action,
            'subject' => $this->ticket->subject,
            'priority' => $this->ticket->priority,
            'status' => $this->ticket->status,
            'tenant_id' => $this->ticket->tenant_id,
            'tenant_name' => $this->ticket->tenant?->name ?? ('Tenant #'.$this->ticket->tenant_id),
            'author_name' => $this->authorName ?? $this->ticket->created_by_name,
            'last_activity_at' => $this->ticket->last_activity_at?->toIso8601String(),
        ];
    }
}
