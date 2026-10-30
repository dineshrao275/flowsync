<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use App\Services\SubscriptionService;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * Whole-product API enforcement.
 *
 * An HRMS-only tenant answers 403 (never 500) on every TMS surface, and a
 * TMS-only tenant answers 403 on every HRMS surface — direct URLs and API
 * calls included, not just hidden navigation. Product denials carry the
 * `X-Product-Denied` header so the SPA can route to the plan page.
 */
class ProductAccessTest extends TestCase
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

    public function test_an_hrms_only_tenant_is_denied_every_tms_surface(): void
    {
        $this->assignPlan('hrms-starter');
        $this->login('admin@flowsync.test');

        foreach ([
            '/api/workspaces',
            '/api/projects',
            '/api/dashboard',
            '/api/analytics/overview',
            '/api/search/tasks?q=ab',
            '/api/search/global?q=ab',
            '/api/reports/overview',
        ] as $url) {
            $response = $this->getJson($url);
            $response->assertForbidden();
            $this->assertSame('tms', $response->headers->get('X-Product-Denied'), "Missing product header for {$url}");
        }
    }

    public function test_an_hrms_only_tenant_keeps_its_hrms_home(): void
    {
        $this->assignPlan('hrms-starter');
        $this->login('admin@flowsync.test');

        $this->getJson('/api/my/hr')->assertOk();
    }

    public function test_a_tms_only_tenant_is_denied_every_hrms_surface(): void
    {
        $this->assignPlan('tms-starter');
        $this->login('admin@flowsync.test');

        foreach (['/api/hrms/employees', '/api/my/hr', '/api/my/team'] as $url) {
            $this->getJson($url)->assertForbidden();
        }
    }

    public function test_a_tms_only_tenant_keeps_its_tms_home(): void
    {
        $this->assignPlan('tms-starter');
        $this->login('admin@flowsync.test');

        $this->getJson('/api/workspaces')->assertOk();
        $this->getJson('/api/dashboard')->assertOk();
    }

    public function test_a_tenant_with_both_products_reaches_both_surfaces(): void
    {
        $this->assignPlan('tms-starter');
        $this->assignPlan('hrms-starter');
        $this->login('admin@flowsync.test');

        $this->getJson('/api/workspaces')->assertOk();
        $this->getJson('/api/my/hr')->assertOk();
    }

    public function test_session_entitlements_follow_a_plan_change_without_relogin(): void
    {
        $this->login('admin@flowsync.test');

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.products.tms', true)
            ->assertJsonPath('user.products.hrms', true)
            ->assertJsonPath('user.modules', config('subscriptions.modules'));

        $this->assignPlan('hrms-starter');

        $modules = $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.products.tms', false)
            ->assertJsonPath('user.products.hrms', true)
            ->json('user.modules');

        $this->assertContains('hrms.core', $modules);
        $this->assertNotContains('time_tracking', $modules);
        $this->getJson('/api/workspaces')
            ->assertForbidden()
            ->assertHeader('X-Product-Denied', 'tms');
        $this->getJson('/api/my/hr')->assertOk();

        $this->assignPlan('tms-starter');

        $modules = $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.products.tms', true)
            ->assertJsonPath('user.products.hrms', true)
            ->json('user.modules');

        $this->assertContains('hrms.core', $modules);
        $this->assertContains('time_tracking', $modules);
        $this->getJson('/api/workspaces')->assertOk();
        $this->getJson('/api/my/hr')->assertOk();
    }
}
