<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One email summarising the notifications held back for a person (digest mode or
 * throttle overflow). Like TaskNotificationMail it snapshots scalars only — the
 * lines are composed at construction in the recipient's language, so nothing is
 * re-queried inside the queue worker.
 */
class TaskDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{line: string, url: string}>  $items
     */
    public function __construct(public array $items, ?string $copyLocale = null)
    {
        if ($copyLocale !== null) {
            $this->locale($copyLocale);
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: trans_choice('notifications.digest.subject', count($this->items), ['count' => count($this->items), 'app' => config('app.name')], $this->locale));
    }

    public function content(): Content
    {
        return new Content(text: 'emails.task-digest');
    }
}
