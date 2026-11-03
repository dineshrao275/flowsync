<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

/** Which tenants still use the legacy per-tenant GitHub webhook (settings.github.enabled), so they can be moved to the GitHub App. */
class GitLegacyStatus extends Command
{
    protected $signature = 'git:legacy-status';

    protected $description = 'List tenants that still use the legacy GitHub webhook integration';

    public function handle(): int
    {
        $rows = Tenant::query()->whereNotNull('settings')->get(['id', 'name', 'slug', 'settings'])
            ->filter(fn (Tenant $t) => ! empty(((array) $t->settings)['github']['enabled']))
            ->map(fn (Tenant $t) => [$t->id, $t->slug, $t->name])->values()->all();

        if ($rows === []) {
            $this->info('No tenant uses the legacy GitHub webhook.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Slug', 'Name'], $rows);
        $this->line(count($rows).' tenant(s) still use the legacy webhook; the endpoint keeps working until they move to the GitHub App.');

        return self::SUCCESS;
    }
}
