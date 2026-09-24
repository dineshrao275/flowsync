<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\SubscriptionPlan;
use App\Models\SystemUser;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class SystemAdminTest extends TestCase
{
    use IsolatesDatabase;

    private function loginSuperAdmin(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();
    }

    public function test_system_settings_are_managed_through_the_system_db(): void
    {
        $this->loginSuperAdmin();

        $this->getJson('/api/system/settings')
            ->assertOk()
            ->assertJsonPath('settings.app_name', 'FlowSync')
            ->assertJsonPath('settings.public_registration', false)
            ->assertJsonPath('settings.maintenance_mode', false);

        $this->putJson('/api/system/settings', [
            'app_name' => 'FlowSync Cloud',
            'public_registration' => true,
            'maintenance_mode' => true,
        ])->assertOk()
            ->assertJsonPath('settings.app_name', 'FlowSync Cloud')
            ->assertJsonPath('settings.public_registration', true);

        $this->assertSame('1', PlatformSetting::value('public_registration'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.settings_updated']);

        // An inactive plan cannot become the default.
        $plan = SubscriptionPlan::orderBy('sort_order')->first();
        $plan->update(['is_active' => false]);

        $this->putJson('/api/system/settings', ['default_plan_id' => $plan->id])
            ->assertStatus(422);
    }

    public function test_register_respects_the_public_registration_platform_setting(): void
    {
        $this->loginSuperAdmin();
        $this->putJson('/api/system/settings', ['public_registration' => false])->assertOk();

        $this->postJson('/api/register', [
            'name' => 'New Owner',
            'email' => 'new@owner.test',
            'password' => 'password',
            'password_confirmation' => 'password',
            'business_name' => 'New Co',
        ])->assertStatus(403);

        $this->putJson('/api/system/settings', ['public_registration' => true])->assertOk();

        $this->postJson('/api/register', [
            'name' => 'New Owner',
            'email' => 'new@owner.test',
            'password' => 'password',
            'password_confirmation' => 'password',
            'business_name' => 'New Co',
        ])->assertOk()->assertJsonPath('user.email', 'new@owner.test');
    }

    public function test_super_admin_accounts_can_be_listed_and_created(): void
    {
        $this->loginSuperAdmin();

        $this->getJson('/api/system/users')
            ->assertOk()
            ->assertJsonPath('users.0.email', 'superadmin@flowsync.test');

        $this->getJson('/api/system/users?q=super')
            ->assertOk()
            ->assertJsonCount(1, 'users');

        $this->postJson('/api/system/users', [
            'name' => 'Second Admin',
            'email' => 'second@flowsync.test',
            'password' => 'password123',
        ])->assertCreated()
            ->assertJsonPath('user.is_super_admin', true);

        $this->assertNotNull(SystemUser::where('email', 'second@flowsync.test')->first());
        $this->assertDatabaseHas('audit_logs', ['action' => 'system.user_created']);

        $this->postJson('/api/system/users', [
            'name' => 'Dup',
            'email' => 'second@flowsync.test',
            'password' => 'password123',
        ])->assertStatus(422);
    }

    public function test_audit_feed_merges_audit_and_impersonation_rows(): void
    {
        $this->loginSuperAdmin();

        // Force an impersonation round-trip so impersonation_logs has a row.
        $globex = $this->globex();
        $this->postJson('/api/impersonate', ['user_id' => 1, 'tenant_id' => $globex->id])->assertOk();
        $this->postJson('/api/impersonate/stop')->assertOk();

        $this->putJson('/api/system/settings', ['app_name' => 'FlowSync Pro'])->assertOk();

        // The ride round-trip leaves ONE impersonation_logs row (ended_at set).
        $this->getJson('/api/system/audit-logs')
            ->assertOk()
            ->assertJsonPath('pagination.total', 2) // completed impersonation + settings_updated
            ->assertJsonStructure(['items' => [['id', 'type', 'action', 'created_at']]]);

        $this->getJson('/api/system/audit-logs?type=impersonation')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.action', 'impersonation.ended');

        $this->getJson('/api/system/audit-logs?type=audit')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('items.0.action', 'platform.settings_updated');
    }

    public function test_module_feature_grid_reads_and_toggles(): void
    {
        $this->loginSuperAdmin();

        $this->getJson('/api/system/features')
            ->assertOk()
            ->assertJsonPath('modules.0', 'time_tracking')
            ->assertJsonStructure(['plans' => [['id', 'slug', 'name', 'modules']]]);

        $starter = SubscriptionPlan::where('slug', 'starter')->firstOrFail();
        $this->assertTrue($starter->hasModule('time_tracking'));
        $this->assertFalse($starter->hasModule('reports'));

        $this->putJson("/api/system/features/{$starter->id}", [
            'module' => 'reports',
            'enabled' => true,
        ])->assertOk()
            ->assertJsonPath('plan.modules.1', 'reports');

        $this->assertTrue($starter->fresh()->hasModule('reports'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'plan.module_toggled']);

        $this->putJson("/api/system/features/{$starter->id}", [
            'module' => 'reports',
            'enabled' => false,
        ])->assertOk();

        $this->assertFalse($starter->fresh()->hasModule('reports'));
    }

    public function test_platform_analytics_aggregates_across_tenants(): void
    {
        $this->loginSuperAdmin();

        $this->getJson('/api/system/analytics')
            ->assertOk()
            ->assertJsonStructure([
                'tenants' => ['total', 'by_status', 'provisioning_failed', 'trashed'],
                'subscriptions' => ['total', 'by_status'],
                'resources' => ['users', 'workspaces', 'projects', 'tasks'],
                'top_tenants' => [['id', 'tenant', 'users']],
            ])
            ->assertJsonPath('tenants.total', 2)
            ->assertJsonPath('resources.users', 5) // acme 4 + globex 1
            ->assertJsonPath('top_tenants.0.users', 4); // acme biggest
    }
}
