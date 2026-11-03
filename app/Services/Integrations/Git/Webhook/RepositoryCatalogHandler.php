<?php

declare(strict_types=1);

namespace App\Services\Integrations\Git\Webhook;

use App\Jobs\SyncGitInstallation;
use App\Models\GitConnection;
use App\Models\GitRepository;
use App\Models\ProjectRepository;
use Illuminate\Support\Facades\Schema;

/** Keeps the mirrored repository catalog current from `installation_repositories` and `repository` events. */
class RepositoryCatalogHandler
{
    /** @param  array<string, mixed>  $payload */
    public function handleSelection(GitConnection $connection, string $action, array $payload): string
    {
        if ($action === 'added') {
            // The event carries a thin summary; a sync brings the full row (branch, visibility, urls).
            SyncGitInstallation::dispatch($connection->id);

            return GitWebhookProcessor::PROCESSED;
        }
        if ($action !== 'removed') {
            return GitWebhookProcessor::IGNORED;
        }

        $ids = collect((array) ($payload['repositories_removed'] ?? []))->pluck('id')->map(fn ($id) => (int) $id)->all();
        GitRepository::query()->where('git_connection_id', $connection->id)->whereIn('provider_repo_id', $ids)->update(['available' => false]);

        return GitWebhookProcessor::PROCESSED;
    }

    /** @param  array<string, mixed>  $payload */
    public function handleRepository(GitConnection $connection, string $action, array $payload): string
    {
        $repo = (array) ($payload['repository'] ?? []);
        $row = GitRepository::query()->where('git_connection_id', $connection->id)->where('provider_repo_id', (int) ($repo['id'] ?? 0))->first();
        if ($row === null || ! in_array($action, ['renamed', 'transferred', 'archived', 'unarchived', 'privatized', 'publicized', 'edited', 'deleted'], true)) {
            return GitWebhookProcessor::IGNORED;
        }

        if ($action === 'deleted') {
            $row->forceFill(['available' => false])->save();

            return GitWebhookProcessor::PROCESSED;
        }

        $row->forceFill([
            'full_name' => (string) ($repo['full_name'] ?? $row->full_name),
            'name' => (string) ($repo['name'] ?? $row->name),
            'owner' => (string) ($repo['owner']['login'] ?? $row->owner),
            'default_branch' => $repo['default_branch'] ?? $row->default_branch,
            'private' => (bool) ($repo['private'] ?? $row->private),
            'archived' => (bool) ($repo['archived'] ?? $row->archived),
            'html_url' => $repo['html_url'] ?? $row->html_url,
        ])->save();
        $this->refreshLinks($connection, $row);

        return GitWebhookProcessor::PROCESSED;
    }

    /** A rename or transfer changes the name every following project shows. Runs inside the tenant DB. */
    private function refreshLinks(GitConnection $connection, GitRepository $row): void
    {
        if (Schema::hasTable('project_repositories')) {
            ProjectRepository::query()->where('git_connection_uuid', $connection->uuid)->where('provider_repo_id', $row->provider_repo_id)
                ->update(['full_name' => $row->full_name, 'html_url' => $row->html_url]);
        }
    }
}
