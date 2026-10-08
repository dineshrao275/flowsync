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
            // The grid is grouped for display; `modules` is now inside groups.
            ->assertJsonPath('groups.0.modules.0.key', 'time_tracking')
            ->assertJsonPath('groups.0.label', 'Platform')
            ->assertJsonStructure([
                'groups' => [['key', 'label', 'modules' => [['key', 'label', 'depth']]]],
                'plans' => [['id', 'slug', 'name', 'modules']],
            ]);

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

    public function test_super_admin_notification_endpoints_return_empty_payloads(): void
    {
        $this->loginSuperAdmin();

        // A platform super admin has no tenant database, so the personal
        // notification endpoints must answer with empty payloads instead of
        // hitting the tenant-only `notifications` table.
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'notifications')
            ->assertJsonPath('unread_count', 0)
            ->assertJsonPath('pagination.total', 0);

        $this->getJson('/api/notifications/unread')->assertOk()->assertJsonPath('count', 0);

        $this->postJson('/api/notifications/mark-all-read')
            ->assertOk()
            ->assertJsonPath('count', 0);

        $this->postJson('/api/notifications/1/read')->assertNotFound();
    }

    public function test_super_admin_remains_blocked_from_tenant_domain_endpoints(): void
    {
        $this->loginSuperAdmin();

        $this->getJson('/api/dashboard')->assertForbidden();
        $this->getJson('/api/analytics/overview')->assertForbidden();
    }

    public function test_impersonating_super_admin_reads_tenant_notifications(): void
    {
        $this->loginSuperAdmin();

        $acme = $this->acme();
        $this->postJson('/api/impersonate', ['user_id' => 1, 'tenant_id' => $acme->id])->assertOk();

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 0)
            ->assertJsonCount(0, 'notifications');

        $this->getJson('/api/notifications/unread')->assertOk()->assertJsonPath('count', 0);
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
                'growth' => [['date', 'label', 'tenants']],
                'plans' => ['rows' => [['plan', 'slug', 'subscriptions', 'price_cents', 'monthly_cents']], 'monthly_cents', 'active_subscriptions'],
            ])
            ->assertJsonPath('tenants.total', 2)
            ->assertJsonPath('resources.users', 5) // acme 4 + globex 1
            ->assertJsonPath('top_tenants.0.users', 4); // acme biggest
    }

    public function test_platform_analytics_reports_30_day_signup_trend_and_plan_mix(): void
    {
        $this->loginSuperAdmin();

        $response = $this->getJson('/api/system/analytics')->assertOk();

        // 30 daily buckets, ending today, each carrying the signup count.
        $growth = $response->json('growth');
        $this->assertCount(30, $growth);
        $this->assertSame(
            now()->format('Y-m-d'),
            $growth[29]['date'],
        );
        $this->assertSame(
            array_sum(array_column($growth, 'tenants')),
            $response->json('tenants.total'),
        );

        // Plan mix covers the whole catalog, zero counts before anyone subscribes.
        $rows = collect($response->json('plans.rows'));
        $this->assertSame(
            ['starter', 'pro', 'business', 'enterprise'],
            $rows->pluck('slug')->all(),
        );
        $this->assertSame(0, $rows->sum('subscriptions'));
        $this->assertSame(0, $response->json('plans.active_subscriptions'));
        $this->assertSame(0, $response->json('plans.monthly_cents'));

        // Subscribing shows up in the mix and the monthly rollup.
        $pro = SubscriptionPlan::where('slug', 'pro')->firstOrFail();
        $this->postJson('/api/tenants/'.$this->acme()->id.'/subscription', [
            'plan_id' => $pro->id,
        ])->assertOk();

        $this->getJson('/api/system/analytics')
            ->assertOk()
            ->assertJsonPath('plans.rows.1.slug', 'pro')
            ->assertJsonPath('plans.rows.1.subscriptions', 1)
            ->assertJsonPath('plans.rows.1.monthly_cents', $pro->price_cents)
            ->assertJsonPath('plans.monthly_cents', $pro->price_cents)
            ->assertJsonPath('plans.active_subscriptions', 1);

        // Annual plans are normalized to a monthly figure for the rollup.
        $pro->update(['billing_cycle' => 'annual', 'price_cents' => 12000]);
        $this->getJson('/api/system/analytics')
            ->assertOk()
            ->assertJsonPath('plans.rows.1.monthly_cents', 1000)
            ->assertJsonPath('plans.monthly_cents', 1000);
    }
}
