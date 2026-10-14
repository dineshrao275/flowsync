<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * New-lead notification to the sales inbox. Snapshots scalars at
 * construction — no models ride the queued job.
 */
class LeadReceivedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $leadName,
        public string $leadEmail,
        public ?string $company,
        public string $message,
        public string $source,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "New {$this->source} request from {$this->leadName}");
    }

    public function content(): Content
    {
        return new Content(text: 'emails.lead-received');
    }
}
