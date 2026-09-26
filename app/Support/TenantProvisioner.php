<?php

namespace App\Support;

use App\Models\Permission;
use App\Models\Priority;
use App\Models\ProjectRole;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUserRouting;
use App\Models\User;
use App\Services\TenantLifecycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantProvisioner
{
    /**
     * Isolated provisioning pipeline: connect system → CREATE DATABASE → run tenant
     * migrations → seed catalogs + owner into the tenant's own database → sync the
     * central tenant_users routing index → activate. Idempotent + resumable, so
     * `tenants:provision` can repair a partially-provisioned tenant.
     */
    public function provisionIsolated(Tenant $tenant, TenantDatabaseManager $dbm, TenantLifecycle $lifecycle): void
    {
        $dbm->connectSystem();

        // Idempotent repair: only re-enter the provisioning status when the tenant
        // is not already serviceable (lifecycle forbids active/trial → provisioning).
        // A serviceable-but-half-provisioned tenant (e.g. stuck after a failed
        // migration) is repaired in place without a forbidden lifecycle transition.
        if (! $tenant->isServiceable() || ! $tenant->isProvisioned()) {
            if ($lifecycle->canTransition($tenant, Tenant::STATUS_PROVISIONING)) {
                $lifecycle->transition($tenant, Tenant::STATUS_PROVISIONING);
            }
        }

        $dbm->createDatabase($tenant);
        $tenant->refresh();

        $dbm->using($tenant, function () use ($dbm, $tenant): void {
            $dbm->migrateTenant($tenant);
            $this->seed($tenant);
            $tenant->update(['provisioning_status' => Tenant::PROVISIONING_SEEDED]);
        });

        $this->syncRouting($dbm, $tenant);

        $tenant->update([
            'provisioning_status' => Tenant::PROVISIONING_PROVISIONED,
            'provisioned_at' => $tenant->provisioned_at ?? now(),
        ]);

        $lifecycle->transition($tenant, $tenant->trial_ends_at ? Tenant::STATUS_TRIAL : Tenant::STATUS_ACTIVE);
    }

    private function seed(Tenant $tenant): void
    {
        $permissions = collect(config('permissions.permissions'))->map(function (array $item) {
            return Permission::firstOrCreate(
                ['slug' => $item['slug']],
                [
                    'name' => $item['name'],
                    'description' => $item['description'] ?? null,
                ]
            );
        });

        $slugsBySelector = app(PermissionSelector::class);
        $adminRole = null;

        foreach (config('permissions.roles') as $slug => $role) {
            $model = Role::firstOrCreate(
                ['slug' => $slug],
                ['name' => $role['name']]
            );

            $allowed = $slugsBySelector->resolve($role['permissions'], $slugsBySelector->catalog());

            $model->permissions()->sync($permissions->whereIn('slug', $allowed)->pluck('id'));

            if ($slug === 'admin') {
                $adminRole = $model;
            }
        }

        $this->provisionPriorities();
        $this->provisionProjectRoles();
        $this->createAdmin($tenant, $adminRole);
        $this->ensureDefaultUser($adminRole);
    }

    private function provisionPriorities(): void
    {
        $defaultSlug = config('priorities.default_slug');

        foreach (config('priorities.priorities') as $item) {
            Priority::firstOrCreate(
                ['slug' => $item['slug']],
                [
                    'name' => $item['name'],
                    'value' => $item['value'],
                    'color' => $item['color'],
                    'position' => $item['position'],
                    'is_default' => $item['slug'] === $defaultSlug,
                ]
            );
        }
    }

    private function provisionProjectRoles(): void
    {
        foreach (config('project_roles.roles') as $slug => $role) {
            ProjectRole::firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $role['name'],
                    'is_system' => true,
                    'permissions' => $role['permissions'] === '*'
                        ? ['*']
                        : $role['permissions'],
                ]
            );
        }
    }

    private function createAdmin(Tenant $tenant, ?Role $adminRole): void
    {
        if (! $adminRole) {
            return;
        }

        $user = User::firstOrCreate(
            ['email' => "owner@{$tenant->slug}.test"],
            ['name' => "{$tenant->name} Owner", 'password' => 'password']
        );

        $user->roles()->syncWithoutDetaching([$adminRole->id]);
    }

    /**
     * Every tenant DB has exactly one undeletable default user. New tenants get
     * the provisioned owner; existing ones are repaired here (idempotent, so
     * `tenants:provision` backfills tenants created before the column existed).
     */
    private function ensureDefaultUser(?Role $adminRole): void
    {
        if (User::query()->default()->exists()) {
            return;
        }

        $candidate = $adminRole
            ? User::query()->whereHas('roles', fn ($query) => $query->whereKey($adminRole->getKey()))->orderBy('id')->first()
            : null;

        $candidate ??= User::query()->orderBy('id')->first();

        $candidate?->update(['is_default' => true]);
    }

    /**
     * Mirror every user in the tenant DB into the central tenant_users routing
     * index (this is read at login time in isolated mode). Idempotent — safe to
     * re-run after users are added to a tenant database (e.g. demo users seeded
     * after provisioning).
     */
    public function syncRouting(TenantDatabaseManager $dbm, Tenant $tenant): void
    {
        $users = $dbm->using($tenant, fn () => DB::table('users')->select('id', 'email', 'name')->get());

        foreach ($users as $user) {
            TenantUserRouting::updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'email' => Str::lower((string) $user->email),
                ],
                [
                    'user_id' => (int) $user->id,
                    'name' => $user->name,
                ]
            );
        }
    }
}
