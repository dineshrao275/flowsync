<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One billing email (price change, trial ending, card expiring, plan change scheduled, payment action needed). It holds
 * the already-translated subject and lines — composed by BillingNotifier in the tenant's language — so the queue worker
 * re-queries nothing and no model rides the queue.
 */
class BillingNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param  list<string>  $lines */
    public function __construct(public string $mailSubject, public string $greeting, public array $lines, public ?string $actionUrl = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.billing-notice',
            text: 'emails.billing-notice-text',
        );
    }
}
