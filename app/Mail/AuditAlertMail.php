<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AuditAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public int|string $breakRowId,
        public string $breakReason,
        public int $checkedCount,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '['.config('app.name').'] Audit log integrity check failed',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.audit-alert',
            text: 'emails.audit-alert-text',
        );
    }
}
