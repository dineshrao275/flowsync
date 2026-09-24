<?php

namespace App\Console\Commands;

use Database\Seeders\ScaleDataSeeder;
use Illuminate\Console\Command;

class SeedScaleData extends Command
{
    protected $signature = 'tenants:seed-scale
        {--tenants=100 : Number of tenants to create}
        {--users=10 : Users per tenant}
        {--workspaces=5 : Workspaces per tenant}
        {--projects=5 : Projects per workspace}
        {--tasks=100 : Tasks per project}
        {--no-related : Skip comments/work logs/notifications}';

    protected $description = 'Seed a large, tenant-isolated demo dataset (super admin + tenants + users + workspaces + projects + tasks)';

    public function handle(ScaleDataSeeder $seeder): int
    {
        $count = (int) $this->option('tenants');

        if ($count <= 0) {
            $this->error('--tenants must be a positive integer.');

            return self::FAILURE;
        }

        $this->info("Seeding scale data: {$count} tenants, {$this->option('users')} users each, "
            ."{$this->option('workspaces')} workspaces, {$this->option('projects')} projects, "
            ."{$this->option('tasks')} tasks each.");

        $seeder->run(
            tenants: $count,
            usersPerTenant: (int) $this->option('users'),
            workspacesPerTenant: (int) $this->option('workspaces'),
            projectsPerWorkspace: (int) $this->option('projects'),
            tasksPerProject: (int) $this->option('tasks'),
            related: ! $this->option('no-related'),
        );

        return self::SUCCESS;
    }
}
