<?php

namespace App\Console\Commands;

use Database\Seeders\ScaleDataSeeder;
use Illuminate\Console\Command;

class SeedScaleData extends Command
{
    protected $signature = 'tenants:seed-scale
        {--tenants=100 : Number of tenants to create}
        {--users=10 : Users per tenant}
        {--workspaces=5-10 : Workspaces per tenant (a count, or a min-max range)}
        {--projects=5-10 : Projects per workspace (a count, or a min-max range)}
        {--tasks=100 : Tasks per project}
        {--no-related : Skip comments/work logs/notifications}
        {--seed= : Seed for reproducible per-tenant workspace/project counts}
        {--dry-run : Print the projected dataset size without writing anything}';

    protected $description = 'Seed a large, tenant-isolated demo dataset (super admin + tenants + users + workspaces + projects + tasks)';

    public function handle(ScaleDataSeeder $seeder): int
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

        $minWorkspaces = min($workspaces);
        $maxWorkspaces = max($workspaces);
        $minProjects = min($projects);
        $maxProjects = max($projects);

        $this->info("Seeding scale data: {$tenants} tenants, {$users} users each, "
            ."{$minWorkspaces}".($minWorkspaces === $maxWorkspaces ? '' : "-{$maxWorkspaces}").' workspaces, '
            ."{$minProjects}".($minProjects === $maxProjects ? '' : "-{$maxProjects}").' projects, '
            ."{$tasks} tasks each.");

        if ($this->option('dry-run')) {
            $min = $tenants * $minWorkspaces * $minProjects * $tasks;
            $max = $tenants * $maxWorkspaces * $maxProjects * $tasks;
            $expected = $min === $max ? number_format($min) : number_format($min).'-'.number_format($max);

            $this->table(['Metric', 'Projected'], [
                ['Tenants', number_format($tenants)],
                ['Users', number_format($tenants * $users)],
                ['Workspaces', $minWorkspaces === $maxWorkspaces
                    ? number_format($tenants * $minWorkspaces)
                    : number_format($tenants * $minWorkspaces).'-'.number_format($tenants * $maxWorkspaces)],
                ['Projects', $minProjects === $maxProjects
                    ? number_format($tenants * $minWorkspaces * $minProjects)
                    : number_format($tenants * $minWorkspaces * $minProjects).'-'.number_format($tenants * $maxWorkspaces * $maxProjects)],
                ['Tasks', $expected],
            ]);

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
