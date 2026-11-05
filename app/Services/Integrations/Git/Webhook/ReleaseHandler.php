<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git\Webhook;

use App\Models\GitConnection;
use Illuminate\Support\Str;

/** `release` published: one activity line on every task the release name, tag or notes mention. */
class ReleaseHandler implements GitEventHandler
{
    public function __construct(private readonly GitLinker $linker) {}

    public function handle(GitConnection $connection, string $event, string $action, array $payload, string $delivery): string
    {
        $release = $payload['release'] ?? null;
        if ($action !== 'published' || ! is_array($release) || empty($release['id']) || $this->linker->isBot($payload)) {
            return GitWebhookProcessor::IGNORED;
        }

        $keys = $this->linker->keysIn($release['name'] ?? null, $release['tag_name'] ?? null, Str::limit((string) ($release['body'] ?? ''), GitLinker::BODY_CAP, ''));
        $changed = false;

        foreach ($this->linker->links($connection, $this->linker->repoId($payload)) as $link) {
            foreach ($this->linker->tasks($link, $keys) as $task) {
                if ($this->linker->claimOnce($link, $task, 'release', (string) $release['id'])) {
                    $this->linker->emit($task, $link, $delivery, [
                        'kind' => 'release', 'state' => 'published',
                        'title' => Str::limit((string) ($release['name'] ?: $release['tag_name'] ?? ''), 255, ''),
                        'url' => $this->linker->safeUrl($release['html_url'] ?? null),
                    ]);
                    $changed = true;
                }
            }
        }

        return $changed ? GitWebhookProcessor::PROCESSED : GitWebhookProcessor::IGNORED;
    }
}
