<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupportMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly SupportTicket $ticket,
        public readonly SupportTicketMessage $message,
    ) {}

    public function broadcastOn(): array
    {
        if ($this->message->is_internal) {
            return [new PrivateChannel('support.ticket.'.$this->ticket->id.'.staff')];
        }

        return [
            new PrivateChannel('support.ticket.'.$this->ticket->id),
            new PrivateChannel('support.ticket.'.$this->ticket->id.'.staff'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'support.message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'ticket_status' => $this->ticket->status,
            'last_activity_at' => $this->ticket->last_activity_at?->toIso8601String(),
            'message' => [
                'id' => $this->message->id,
                'author_type' => $this->message->author_type,
                'author_name' => $this->message->author_name,
                'body' => $this->message->body,
                'is_internal' => (bool) $this->message->is_internal,
                'created_at' => $this->message->created_at?->toIso8601String(),
            ],
        ];
    }
}
