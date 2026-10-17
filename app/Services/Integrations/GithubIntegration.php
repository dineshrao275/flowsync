<?php

namespace App\Services\Integrations;

use App\Models\Task;
use App\Models\Tenant;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * GitHub → task links (F10.7). A webhook per tenant, signed with a secret only we and the tenant admin know
 * (`X-Hub-Signature-256`, HMAC-SHA256 of the raw body). A pull request or commit that mentions a task key
 * (`ABC-123`) in its title, branch or message leaves a `task.vcs_event` line in that task's activity feed. It never
 * moves, assigns or edits a task — linking is information, not automation (use the automation rules for that).
 */
class GithubIntegration
{
    private const KEY = '/\b([A-Z][A-Z0-9]{1,9}-\d{1,6})\b/';

    public function __construct(private readonly ActivityLogger $activity) {}

    public function isEnabled(Tenant $tenant): bool
    {
        return (bool) ($tenant->settings['github']['enabled'] ?? false) && ! empty($tenant->settings['github']['secret']);
    }

    public function secret(Tenant $tenant): ?string
    {
        $enc = $tenant->settings['github']['secret'] ?? null;

        return $enc ? Crypt::decryptString($enc) : null;
    }

    /** Switch on (or off) and, when switching on, mint a fresh secret that is returned ONCE. */
    public function configure(Tenant $tenant, bool $enabled, bool $rotate): ?string
    {
        $settings = (array) ($tenant->settings ?? []);
        $current = (array) ($settings['github'] ?? []);
        $plain = null;

        if ($enabled && ($rotate || empty($current['secret']))) {
            $plain = 'ghs_'.Str::random(40);
            $current['secret'] = Crypt::encryptString($plain);
        }
        $current['enabled'] = $enabled;
        $settings['github'] = $current;
        $tenant->settings = $settings;
        $tenant->save();

        return $plain;
    }

    public function validSignature(string $rawBody, ?string $header, string $secret): bool
    {
        return is_string($header) && hash_equals('sha256='.hash_hmac('sha256', $rawBody, $secret), $header);
    }

    /**
     * Record the event on every task it names. Call inside the tenant's database.
     *
     * @param  array<string, mixed>  $payload
     * @return int number of tasks linked
     */
    public function handle(string $event, array $payload): int
    {
        $items = match ($event) {
            'pull_request' => $this->pullRequest($payload),
            'push' => $this->push($payload),
            default => [],
        };

        $linked = 0;
        foreach ($items as $item) {
            foreach (array_unique($item['keys']) as $key) {
                $task = Task::query()->where('key', $key)->first();
                if ($task === null) {
                    continue;
                }
                $this->activity->log(Task::class, $task->id, 'task.vcs_event', $item['data'] + ['key' => $key]);
                $linked++;
            }
        }

        return $linked;
    }

    /** @return list<array{keys: list<string>, data: array<string, mixed>}> */
    private function pullRequest(array $p): array
    {
        $pr = $p['pull_request'] ?? null;
        $action = $p['action'] ?? '';
        if (! is_array($pr) || ! in_array($action, ['opened', 'reopened', 'closed', 'ready_for_review'], true)) {
            return [];
        }

        $state = $action === 'closed' ? (($pr['merged'] ?? false) ? 'merged' : 'closed') : 'open';
        $text = ($pr['title'] ?? '').' '.($pr['head']['ref'] ?? '').' '.($pr['body'] ?? '');

        return [[
            'keys' => $this->keysIn($text),
            'data' => ['provider' => 'github', 'kind' => 'pull_request', 'state' => $state, 'title' => Str::limit((string) ($pr['title'] ?? ''), 140), 'url' => $this->safeUrl($pr['html_url'] ?? null), 'repo' => $p['repository']['full_name'] ?? null],
        ]];
    }

    /** @return list<array{keys: list<string>, data: array<string, mixed>}> */
    private function push(array $p): array
    {
        $out = [];
        foreach (array_slice((array) ($p['commits'] ?? []), 0, 20) as $c) {
            $out[] = [
                'keys' => $this->keysIn((string) ($c['message'] ?? '')),
                'data' => ['provider' => 'github', 'kind' => 'commit', 'state' => 'pushed', 'title' => Str::limit(Str::before((string) ($c['message'] ?? ''), "\n"), 140), 'url' => $this->safeUrl($c['url'] ?? null), 'repo' => $p['repository']['full_name'] ?? null],
            ];
        }

        return $out;
    }

    /** @return list<string> */
    private function keysIn(string $text): array
    {
        preg_match_all(self::KEY, $text, $m);

        return array_values(array_unique($m[1]));
    }

    /** Only https links to GitHub are kept, so a forged payload cannot plant an arbitrary link in a task. */
    private function safeUrl(mixed $url): ?string
    {
        return is_string($url) && preg_match('#^https://([a-z0-9-]+\.)*github\.com/#i', $url) ? $url : null;
    }
}
