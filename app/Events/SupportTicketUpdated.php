<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\SupportTicket;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupportTicketUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly SupportTicket $ticket,
        public readonly string $action = 'updated',
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('support.ticket.'.$this->ticket->id),
            new PrivateChannel('support.ticket.'.$this->ticket->id.'.staff'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'support.ticket.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'action' => $this->action,
            'status' => $this->ticket->status,
            'priority' => $this->ticket->priority,
            'assigned_to' => $this->ticket->assigned_to,
            'assignee' => $this->ticket->assignee?->name,
            'last_activity_at' => $this->ticket->last_activity_at?->toIso8601String(),
        ];
    }
}
