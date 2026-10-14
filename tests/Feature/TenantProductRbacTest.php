<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\SubscriptionPlan;
use App\Services\SubscriptionService;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * Product-scoped role/permission management.
 *
 * A tenant only sees the permissions of its subscribed products (plus the
 * shared identity/administration prefixes) in the role catalog, and cannot
 * grant a permission outside those products: an HRMS-only tenant must not
 * view/assign/manage TMS permissions, and vice versa. Tenants with both
 * products (or no subscription = unlimited) keep the whole catalog.
 */
class TenantProductRbacTest extends TestCase
{
    use IsolatesDatabase;

    private function login(string $email): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
    }

    private function assignPlan(string $slug): void
    {
        $plan = SubscriptionPlan::where('slug', $slug)->firstOrFail();

        app(SubscriptionService::class)->assign($this->acme(), $plan);
    }

    private function permissionId(string $slug): int
    {
        $this->connectTenant('acme');

        return Permission::where('slug', $slug)->firstOrFail()->id;
    }

    /** @return list<string> */
    private function catalogSlugs(): array
    {
        return collect($this->getJson('/api/roles')->assertOk()->json('permissions'))->pluck('slug')->all();
    }

    public function test_an_hrms_only_tenant_sees_no_tms_permissions_in_the_role_catalog(): void
    {
        $this->assignPlan('hrms-starter');
        $this->login('admin@flowsync.test');

        $slugs = $this->catalogSlugs();

        $this->assertContains('hrms.employees.view', $slugs);
        $this->assertContains('users.view', $slugs);
        $this->assertNotContains('reports.view', $slugs);
        $this->assertSame([], array_values(array_filter($slugs, fn (string $s) => str_starts_with($s, 'workspaces.'))));
    }

    public function test_an_hrms_only_tenant_cannot_grant_a_tms_permission(): void
    {
        $this->assignPlan('hrms-starter');
        $this->login('admin@flowsync.test');

        $response = $this->postJson('/api/roles', [
            'name' => 'Task Leads',
            'slug' => 'task-leads',
            'permissions' => [$this->permissionId('workspaces.view')],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('permissions');
    }

    public function test_a_tms_only_tenant_sees_no_hrms_permissions_in_the_role_catalog(): void
    {
        $this->assignPlan('tms-starter');
        $this->login('admin@flowsync.test');

        $slugs = $this->catalogSlugs();

        $this->assertContains('workspaces.view', $slugs);
        $this->assertContains('users.view', $slugs);
        $this->assertSame([], array_values(array_filter($slugs, fn (string $s) => str_starts_with($s, 'hrms.'))));
    }

    public function test_a_tms_only_tenant_cannot_grant_an_hrms_permission(): void
    {
        $this->assignPlan('tms-starter');
        $this->login('admin@flowsync.test');

        $response = $this->postJson('/api/roles', [
            'name' => 'HR Assistants',
            'slug' => 'hr-assistants',
            'permissions' => [$this->permissionId('hrms.employees.view')],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('permissions');
    }

    public function test_a_tenant_with_both_products_sees_the_whole_catalog(): void
    {
        $this->assignPlan('tms-starter');
        $this->assignPlan('hrms-starter');
        $this->login('admin@flowsync.test');

        $slugs = $this->catalogSlugs();

        $this->assertContains('workspaces.view', $slugs);
        $this->assertContains('hrms.employees.view', $slugs);
        $this->assertContains('users.view', $slugs);
    }

    public function test_an_unlimited_tenant_sees_the_whole_catalog(): void
    {
        $this->login('admin@flowsync.test');

        $slugs = $this->catalogSlugs();

        $this->assertContains('workspaces.view', $slugs);
        $this->assertContains('hrms.employees.view', $slugs);
    }

    public function test_each_catalog_row_names_its_product(): void
    {
        $this->assignPlan('hrms-starter');
        $this->login('admin@flowsync.test');

        $permissions = collect($this->getJson('/api/roles')->assertOk()->json('permissions'));

        $this->assertNotEmpty($permissions);
        $this->assertSame([], $permissions->pluck('product')->filter(fn ($p) => ! in_array($p, ['tms', 'hrms', 'shared'], true))->all());
        $this->assertSame([], $permissions->where('product', 'tms')->values()->all());
        $this->assertSame('hrms', $permissions->firstWhere('slug', 'hrms.employees.view')['product']);
        $this->assertSame('shared', $permissions->firstWhere('slug', 'users.view')['product']);
    }
}
