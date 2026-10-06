<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The single task-notification email, kind-driven (assigned / commented /
 * status_changed / unblocked). It snapshots only scalars at construction — no
 * models ride inside the queue job, so the body cannot leak across tenants when
 * the worker unserializes it on whatever connection is current.
 */
class TaskNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    /** Mentions beyond this cap in one comment are dropped (and flagged). */
    public const MAX_MENTIONS_PER_COMMENT = 20;

    public string $line;

    public string $subjectText;

    public function __construct(
        public string $kind,
        public string $actorName,
        public array $task,
        public string $url,
    ) {
        $this->line = $this->composeLine();
        $this->subjectText = $this->composeSubject();
    }

    /**
     * @param  array<string, mixed>  $data  a TMS notification data payload
     */
    public static function fromData(string $type, string $actorName, array $data, string $url): self
    {
        $kind = match ($type) {
            'task.assigned' => 'assigned',
            'task.commented' => 'commented',
            'task.status_changed' => 'status_changed',
            'task.unblocked' => 'unblocked',
            default => throw new \InvalidArgumentException("Unsupported task notification type: {$type}"),
        };

        return new self($kind, $actorName, $data, $url);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectText);
    }

    public function content(): Content
    {
        return new Content(text: 'emails.task-notification');
    }

    private function composeLine(): string
    {
        $key = $this->task['key'] ?? 'a task';
        $title = $this->task['title'] ?? 'a task';
        $status = $this->task['to_status'] ?? 'a new status';

        return match ($this->kind) {
            'assigned' => "{$this->actorName} assigned {$key} — {$title} to you",
            'commented' => "{$this->actorName} commented on {$key} — {$title}",
            'status_changed' => "{$this->actorName} moved {$key} to {$status}",
            'unblocked' => "{$this->actorName} unblocked {$key} — {$title}",
            default => "{$this->actorName} sent you a task notification",
        };
    }

    private function composeSubject(): string
    {
        $key = $this->task['key'] ?? 'Task';
        $title = $this->task['title'] ?? 'a task';
        $status = $this->task['to_status'] ?? 'a new status';

        return match ($this->kind) {
            'assigned' => "[{$key}] You were assigned {$title}",
            'commented' => "[{$key}] New comment from {$this->actorName}",
            'status_changed' => "[{$key}] Moved to {$status}",
            'unblocked' => "[{$key}] Unblocked",
            default => "[{$key}] Task notification",
        };
    }
}
