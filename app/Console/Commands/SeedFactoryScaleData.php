<?php

namespace App\Console\Commands;

use Database\Seeders\FactoryScaleSeeder;
use Illuminate\Console\Command;

/**
 * Wipe every tenant and reseed the scale dataset through factories.
 *
 * The nuclear counterpart to `tenants:seed-scale`: that command is
 * additive and idempotent (safe to re-run), this one drops all tenant
 * databases and central tenant rows first, then rebuilds the same shape
 * row-by-row through Eloquent factories — slower, but every row is a real
 * model link instead of a bulk insert. Platform rows (super admin, plans,
 * settings, pages, audit history) survive. Requires --force: without it
 * the command refuses, because "oops" is not recoverable here.
 */
class SeedFactoryScaleData extends Command
{
    protected $signature = 'tenants:seed-factory
        {--tenants=100 : Number of scale tenants to create}
        {--users=10 : Users per tenant}
        {--workspaces=5 : Workspaces per tenant (a count, or a min-max range)}
        {--projects=5 : Projects per workspace (a count, or a min-max range)}
        {--tasks=100 : Tasks per project}
        {--no-related : Skip comments/work logs/notifications}
        {--seed= : Seed for reproducible per-tenant workspace/project counts}
        {--dry-run : Print the projected dataset size without writing anything}
        {--force : Confirm the wipe of all existing tenants}';

    protected $description = 'Wipe all tenants and reseed the scale dataset through factories';

    public function handle(FactoryScaleSeeder $seeder): int
    {
        $tenants = (int) $this->option('tenants');

        if ($tenants <= 0) {
            $this->error('--tenants must be a positive integer.');

            return self::FAILURE;
        }

        try {
            $workspaces = $this->parseCountSpec((string) $this->option('workspaces'), '--workspaces');
            $projects = $this->parseCountSpec((string) $this->option('projects'), '--projects');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $users = (int) $this->option('users');
        $tasks = (int) $this->option('tasks');

        if ($users <= 0 || $tasks <= 0) {
            $this->error('--users and --tasks must be positive integers.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->option('dry-run')) {
            $this->error('This drops every tenant database. Pass --force to confirm.');

            return self::FAILURE;
        }

        $min = $tenants * min($workspaces) * min($projects) * $tasks;
        $max = $tenants * max($workspaces) * max($projects) * $tasks;

        $this->info("Factory scale reseed: {$tenants} tenants (acme + globex + scale), "
            ."{$users} users each, ".number_format($min).'-'.number_format($max).' tasks.');

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing was written.');

            return self::SUCCESS;
        }

        $seeder->run(
            tenants: $tenants,
            usersPerTenant: $users,
            workspacesPerTenant: $workspaces,
            projectsPerWorkspace: $projects,
            tasksPerProject: $tasks,
            related: ! $this->option('no-related'),
            seed: $this->option('seed') !== null ? (int) $this->option('seed') : null,
        );

        return self::SUCCESS;
    }

    /**
     * Parse `5` or `5-10` into a [min, max] pair.
     *
     * @return array{0: int, 1: int}
     */
    private function parseCountSpec(string $spec, string $label): array
    {
        $parts = explode('-', trim($spec), 2);

        $min = (int) $parts[0];
        $max = isset($parts[1]) ? (int) $parts[1] : $min;

        if ($min < 1 || $max < 1 || $max < $min) {
            throw new \InvalidArgumentException("{$label} must be a positive count or a min-max range (got '{$spec}').");
        }

        return [$min, $max];
    }
}
