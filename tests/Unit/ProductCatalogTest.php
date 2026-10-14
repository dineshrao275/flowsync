<?php

namespace Tests\Unit;

use App\Support\ProductCatalog;
use PHPUnit\Framework\TestCase;

/**
 * The permission→product map is pure prefix algebra, so it is tested without
 * the framework (like PermissionSelectorTest).
 */
class ProductCatalogTest extends TestCase
{
    public function test_hrms_slugs_map_to_hrms(): void
    {
        $this->assertSame('hrms', ProductCatalog::ofPermission('hrms.view'));
        $this->assertSame('hrms', ProductCatalog::ofPermission('hrms.employees.view_own'));
        $this->assertSame('hrms', ProductCatalog::ofPermission('hrms.payroll.statutory.manage'));
    }

    public function test_shared_prefixes_map_to_null(): void
    {
        foreach (['dashboard.view', 'users.manage', 'roles.view', 'settings.theme', 'billing.view', 'content.manage', 'support.manage', 'tenant.manage', 'webhooks.manage', 'api.manage'] as $slug) {
            $this->assertNull(ProductCatalog::ofPermission($slug), "Expected shared for {$slug}");
        }
    }

    public function test_everything_else_maps_to_tms(): void
    {
        $this->assertSame('tms', ProductCatalog::ofPermission('workspaces.view'));
        $this->assertSame('tms', ProductCatalog::ofPermission('workspaces.manage'));
        $this->assertSame('tms', ProductCatalog::ofPermission('reports.view'));
    }

    public function test_is_allowed_for_filters_by_enabled_products(): void
    {
        $this->assertTrue(ProductCatalog::isAllowedFor('users.view', ['hrms']));
        $this->assertTrue(ProductCatalog::isAllowedFor('hrms.employees.view', ['hrms']));
        $this->assertFalse(ProductCatalog::isAllowedFor('hrms.employees.view', ['tms']));
        $this->assertTrue(ProductCatalog::isAllowedFor('workspaces.view', ['tms']));
        $this->assertFalse(ProductCatalog::isAllowedFor('workspaces.view', ['hrms']));
        $this->assertTrue(ProductCatalog::isAllowedFor('reports.view', ['tms', 'hrms']));
    }
}
