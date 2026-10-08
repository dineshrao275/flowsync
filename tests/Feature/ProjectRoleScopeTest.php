<?php

namespace Tests\Feature;

use App\Models\ProjectRole;
use App\Models\Tenant;
use App\Services\TenantLifecycle;
use App\Support\PermissionScope;
use App\Support\TenantDatabaseManager;
use App\Support\TenantProvisioner;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * Phase B of the member-access plan: the project-role scope variants.
 *
 * Project-role permissions are a separate catalog from the tenant
 * permissions, but the resolution rule is shared (App\Support\PermissionScope,
 * also used by User::granted() and ProjectRole::grants()) — one definition of
 * "own / assigned / all" across both.
 */
class ProjectRoleScopeTest extends TestCase
{
    use IsolatesDatabase;

    public function test_the_project_catalog_carries_scope_variants_for_tasks(): void
    {
        $catalog = collect(config('project_roles.permissions'));

        foreach (config('project_roles.scope_domains') as $domain => $verbs) {
            foreach ($verbs as $verb) {
                foreach (PermissionScope::SCOPES as $scope) {
                    $this->assertContains(
                        "{$domain}.{$verb}_{$scope}",
                        $catalog,
                        "Missing project scope variant {$domain}.{$verb}_{$scope}",
                    );
                }
            }
        }

        // 20 hand-written bases + tasks×5 verbs×3 scopes, no duplicates.
        $this->assertSame(20 + 5 * 3, $catalog->count());
        $this->assertSame($catalog->count(), $catalog->unique()->count(), 'Duplicate slug in project catalog');
    }

    public function test_the_scopes_map_names_exactly_the_generated_slugs(): void
    {
        $declared = collect(config('project_roles.permissions'))
            ->filter(fn (string $slug): bool => ($dot = strrpos($slug, '.')) !== false
                && str_contains(substr($slug, $dot + 1), '_'));

        $generated = collect();

        foreach (config('project_roles.scope_domains') as $domain => $verbs) {
            foreach ($verbs as $verb) {
                foreach (PermissionScope::SCOPES as $scope) {
                    $generated->push("{$domain}.{$verb}_{$scope}");
                }
            }
        }

        $this->assertSame($generated->sort()->values()->all(), $declared->sort()->values()->all());
    }

    public function test_the_legacy_task_slug_still_reads_as_all(): void
    {
        // A project role holding `tasks.view` today keeps reading every task.
        $viewer = ProjectRole::where('slug', 'viewer')->firstOrFail();

        $this->assertTrue($viewer->grants('tasks.view_all'));
        $this->assertTrue($viewer->grants('tasks.view_own'));
        $this->assertTrue($viewer->hasPermission('tasks.view'));
    }

    public function test_project_role_grants_follows_the_scope_lattice(): void
    {
        $own = ProjectRole::create([
            'name' => 'Own Tasks',
            'slug' => 'own-tasks',
            'permissions' => ['tasks.view_own', 'tasks.edit_own'],
        ]);

        $this->assertTrue($own->grants('tasks.view_own'));
        $this->assertFalse($own->grants('tasks.view_assigned'));
        $this->assertFalse($own->grants('tasks.view_all'));
        $this->assertTrue($own->grants('tasks.edit_own'));
        $this->assertFalse($own->grants('tasks.edit_all'));

        $all = ProjectRole::create([
            'name' => 'All Tasks',
            'slug' => 'all-tasks',
            'permissions' => ['tasks.view_all'],
        ]);

        // A wider grant answers a narrower request.
        $this->assertTrue($all->grants('tasks.view_own'));
        $this->assertTrue($all->grants('tasks.view_assigned'));

        // The lead role is '*', so it answers literally everything.
        $lead = ProjectRole::where('slug', 'lead')->firstOrFail();
        $this->assertTrue($lead->grants('tasks.delete_all'));
        $this->assertTrue($lead->grants('tasks.view_own'));
    }

    public function test_a_scoped_slug_is_assignable_through_the_api(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'admin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $this->postJson('/api/project-roles', [
            'name' => 'Own Tasks Only',
            'permissions' => ['tasks.view', 'tasks.view_own', 'tasks.edit_all'],
        ])->assertCreated();
    }

    public function test_re_provisioning_unions_new_slugs_into_system_roles_without_revoking(): void
    {
        $dbm = app(TenantDatabaseManager::class);
        $tenant = Tenant::on($dbm->centralConnectionName())->where('slug', 'acme')->firstOrFail();

        // The state a real tenant is in when this ships: developer provisioned
        // before `tasks.view` was even in its list, plus an admin-granted slug
        // the config no longer cares about.
        $dbm->using($tenant, function (): void {
            $developer = ProjectRole::where('slug', 'developer')->firstOrFail();
            $stored = array_values(array_diff($developer->permissions, ['tasks.view']));
            $stored[] = 'attachments.delete';

            $developer->update(['permissions' => array_values(array_unique($stored))]);
        });

        app(TenantProvisioner::class)->provisionIsolated($tenant, $dbm, app(TenantLifecycle::class));

        $dbm->using($tenant, function (): void {
            $developer = ProjectRole::where('slug', 'developer')->firstOrFail();

            // Config parity restored…
            $this->assertContains('tasks.view', $developer->permissions);

            // …without revoking a single stored slug.
            $this->assertContains('attachments.delete', $developer->permissions);
        });
    }

    public function test_re_provisioning_never_touches_custom_project_roles(): void
    {
        $dbm = app(TenantDatabaseManager::class);
        $tenant = Tenant::on($dbm->centralConnectionName())->where('slug', 'acme')->firstOrFail();

        $dbm->using($tenant, function (): void {
            ProjectRole::create([
                'name' => 'QA',
                'slug' => 'qa',
                'is_system' => false,
                'permissions' => ['tasks.view_own'],
            ]);
        });

        app(TenantProvisioner::class)->provisionIsolated($tenant, $dbm, app(TenantLifecycle::class));

        $dbm->using($tenant, function (): void {
            $qa = ProjectRole::where('slug', 'qa')->firstOrFail();
            $this->assertFalse($qa->is_system);
            $this->assertSame(['tasks.view_own'], $qa->permissions);
        });
    }
}
