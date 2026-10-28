<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\RealisticDemoSeeder;
use Illuminate\Console\Command;

/**
 * Development/demo only. Seeds the realistic, interconnected 150-tenant dataset (config/demo_seed.php).
 * Safe to rerun: a tenant already seeded at the current version is skipped; `--reseed` rebuilds just the demo tenants
 * it owns (identified by their `settings.demo_seed` marker) — it never touches any other tenant, user or platform data.
 */
class SeedRealistic extends Command
{
    protected $signature = 'tenants:seed-realistic
        {--from= : first blueprint index (0-based)}
        {--to= : last blueprint index (inclusive)}
        {--category= : only hrms | tms | both}
        {--slug=* : only these tenant slugs}
        {--reseed : drop and rebuild demo tenants that already exist}
        {--dry-run : print the plan without writing anything}
        {--force : required outside local/testing environments}';

    protected $description = 'Seed the realistic multi-tenant demo dataset (HRMS-only, TMS-only and combined tenants)';

    public function handle(RealisticDemoSeeder $seeder): int
    {
        if (! app()->environment(['local', 'testing', 'development']) && ! $this->option('force')) {
            $this->error('This creates demo tenants and demo logins. Refusing outside local/testing without --force.');

            return self::FAILURE;
        }

        $seeder->setCommand($this);
        $seeder->run(
            from: $this->option('from') !== null ? (int) $this->option('from') : null,
            to: $this->option('to') !== null ? (int) $this->option('to') : null,
            category: $this->option('category') ?: null,
            slugs: array_filter((array) $this->option('slug')),
            reseed: (bool) $this->option('reseed'),
            dryRun: (bool) $this->option('dry-run'),
        );

        return self::SUCCESS;
    }
}
