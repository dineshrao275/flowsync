<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** A scheduled report as a CSV attachment. Scalars only (name + CSV text), so nothing is re-queried in the worker. */
class ScheduledReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $reportName, public string $csv, public int $rows, public bool $truncated) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Your report: :name', ['name' => $this->reportName]));
    }

    public function content(): Content
    {
        return new Content(text: 'emails.scheduled-report');
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->csv, str($this->reportName)->slug().'.csv')->withMime('text/csv')];
    }
}
