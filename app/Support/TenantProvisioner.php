<?php

namespace App\Support;

use App\Models\IssueType;
use App\Models\Permission;
use App\Models\Priority;
use App\Models\ProjectRole;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantUserRouting;
use App\Models\User;
use App\Services\Hrms\Defaults\HrmsDefaultsProvisioner;
use App\Services\TenantLifecycle;
use App\Support\Hrms\HrmsSchema;
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
    /** @param list<string>|null $products products to build the schema for; null = whatever the tenant's plans cover */
    public function provisionIsolated(Tenant $tenant, TenantDatabaseManager $dbm, TenantLifecycle $lifecycle, ?array $products = null): void
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

        $dbm->using($tenant, function () use ($dbm, $tenant, $products): void {
            $dbm->migrateTenant($tenant, $products);
            HrmsSchema::flush();
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
                ['name' => $role['name'], 'is_system' => true]
            );

            $allowed = $slugsBySelector->resolve($role['permissions'], $slugsBySelector->catalog());
            $ids = $permissions->whereIn('slug', $allowed)->pluck('id');

            // A role that predates the flag is a system role by slug.
            if (! $model->is_system) {
                $model->forceFill(['is_system' => true])->save();
            }

            // A fresh role takes the config set outright. An existing one only
            // ever GAINS permissions here: a repair must pick up what the
            // product adds to a default role without ever revoking what the
            // tenant already holds (the project-role rule, applied to tenant
            // roles — a plain sync() reverted tenant edits on every repair).
            $model->wasRecentlyCreated
                ? $model->permissions()->sync($ids)
                : $model->permissions()->syncWithoutDetaching($ids);

            if ($slug === 'admin') {
                $adminRole = $model;
            }
        }

        $this->provisionPriorities();
        $this->provisionIssueTypes();
        $this->provisionProjectRoles();
        if (HrmsSchema::present()) {
            app(HrmsDefaultsProvisioner::class)->provision();
        }
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

    private function provisionIssueTypes(): void
    {
        $types = config('issue_types.types', []);

        foreach ($types as $item) {
            IssueType::firstOrCreate(
                ['slug' => $item['slug']],
                [
                    'name' => $item['name'],
                    'description' => $item['description'] ?? null,
                    'icon' => $item['icon'] ?? null,
                    'color' => $item['color'] ?? null,
                    'is_subtask' => $item['is_subtask'] ?? false,
                    'position' => $item['position'] ?? 0,
                ]
            );
        }
    }

    private function provisionProjectRoles(): void
    {
        foreach (config('project_roles.roles') as $slug => $role) {
            $configured = $role['permissions'] === '*'
                ? ['*']
                : $role['permissions'];

            $model = ProjectRole::firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $role['name'],
                    'is_system' => true,
                    'permissions' => $configured,
                ]
            );

            // Additions only: a system role provisioned before a slug existed
            // must pick it up on repair, but a config edit must never revoke
            // what the tenant already holds. Custom roles are not in config,
            // so this loop cannot touch them.
            $stored = $model->permissions ?? [];

            if ($configured === ['*']) {
                $merged = ['*'];
            } elseif ($stored === ['*']) {
                $merged = ['*'];
            } else {
                $merged = array_values(array_unique(array_merge($stored, $configured)));
            }

            if ($merged !== $stored) {
                $model->permissions = $merged;
                $model->save();
            }
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
