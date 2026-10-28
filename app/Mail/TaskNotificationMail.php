<?php

declare(strict_types=1);

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
 *
 * Copy lives in `lang/<locale>/notifications.php`; the recipient's own language
 * (their `user_settings.locale`, else the request/tenant locale) is resolved by
 * the dispatcher and threaded in as `$copyLocale`, so a Spanish-preferring user
 * gets Spanish mail without the worker re-querying anything.
 */
class TaskNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    /** Mentions beyond this cap in one comment are dropped (and flagged). */
    public const MAX_MENTIONS_PER_COMMENT = 20;

    public string $line;

    public string $subjectText;

    private ?string $copyLocale;

    public function __construct(
        public string $kind,
        public string $actorName,
        public array $task,
        public string $url,
        ?string $copyLocale = null,
    ) {
        $this->copyLocale = $copyLocale;

        if ($copyLocale !== null) {
            // Also pin the rendering locale so the body's `__()` labels resolve
            // in the recipient's language (Mailable::render() honours this).
            $this->locale($copyLocale);
        }

        $this->line = $this->composeLine();
        $this->subjectText = $this->composeSubject();
    }

    /**
     * @param  array<string, mixed>  $data  a TMS notification data payload
     */
    public static function fromData(string $type, string $actorName, array $data, string $url, ?string $copyLocale = null): self
    {
        $kind = match ($type) {
            'task.assigned' => 'assigned',
            'task.commented' => 'commented',
            'task.status_changed' => 'status_changed',
            'task.unblocked' => 'unblocked',
            default => throw new \InvalidArgumentException("Unsupported task notification type: {$type}"),
        };

        return new self($kind, $actorName, $data, $url, $copyLocale);
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
        return $this->t($this->slot().'.line', [
            'actor' => $this->actor(),
            'key' => $this->task['key'] ?? $this->t('notifications.fallback.task'),
            'title' => $this->task['title'] ?? $this->t('notifications.fallback.task'),
            'status' => $this->task['to_status'] ?? $this->t('notifications.fallback.status'),
        ]);
    }

    private function composeSubject(): string
    {
        return $this->t($this->slot().'.subject', [
            'key' => $this->task['key'] ?? $this->t('notifications.fallback.key'),
            'title' => $this->task['title'] ?? $this->t('notifications.fallback.task'),
            'status' => $this->task['to_status'] ?? $this->t('notifications.fallback.status'),
            'actor' => $this->actor(),
        ]);
    }

    /** The trigger slot, with unknown kinds folding onto the generic copy. */
    private function slot(): string
    {
        return match ($this->kind) {
            'assigned', 'commented', 'status_changed', 'unblocked' => "notifications.task.{$this->kind}",
            default => 'notifications.task.other',
        };
    }

    private function actor(): string
    {
        return $this->actorName !== '' ? $this->actorName : $this->t('notifications.fallback.actor');
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function t(string $key, array $replace = []): string
    {
        return trans($key, $replace, $this->copyLocale);
    }
}
