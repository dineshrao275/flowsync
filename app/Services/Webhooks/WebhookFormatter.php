<?php

declare(strict_types=1);

namespace App\Services\Webhooks;

/**
 * Renders a webhook event for the receiver's format.
 *
 *  - `json`  — the signed envelope, unchanged.
 *  - `slack` — `{"text": "…"}`, Slack incoming-webhook / Mattermost compatible.
 *  - `teams` — a MessageCard, accepted by Microsoft Teams incoming webhooks.
 *
 * The chat formats carry one human sentence built from the event type and the few fields the event
 * data reliably has (`key`, `title`, `name`). They deliberately carry nothing else from the event:
 * a chat channel is wider-readable than an API consumer, so no emails, comment bodies or ids leak
 * into it by accident.
 */
final class WebhookFormatter
{
    public const FORMATS = ['json', 'slack', 'teams'];

    /** @param array<string, mixed> $payload the envelope from WebhookConsumer::payload() */
    public static function body(array $payload, string $format): string
    {
        $text = self::summary($payload);

        $body = match ($format) {
            'slack' => ['text' => $text],
            'teams' => [
                '@type' => 'MessageCard',
                '@context' => 'https://schema.org/extensions',
                'summary' => $payload['type'] ?? config('app.name'),
                'themeColor' => '2563EB',
                'title' => config('app.name'),
                'text' => $text,
            ],
            default => $payload,
        };

        return (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string, mixed> $payload */
    public static function summary(array $payload): string
    {
        $type = (string) ($payload['type'] ?? 'event');
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        $what = trim(implode(' ', array_filter([
            self::clean($data['key'] ?? null),
            self::clean($data['title'] ?? ($data['name'] ?? null)),
        ])));

        $verb = ucfirst(str_replace(['.', '_'], ' ', $type));
        $tenant = $payload['tenant']['slug'] ?? null;

        return $verb.($what !== '' ? ': '.$what : '').($tenant ? ' ('.$tenant.')' : '');
    }

    private static function clean(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        // One line, no markup control characters, and bounded.
        $text = preg_replace('/[\r\n\t]+/', ' ', strip_tags((string) $value)) ?? '';

        return $text === '' ? null : mb_substr($text, 0, 140);
    }
}
