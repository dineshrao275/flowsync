<?php

namespace Tests\Feature;

use App\Mail\BillingNoticeMail;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Workspace;
use App\Services\SubscriptionService;
use App\Services\TenantLifecycle;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Concerns\StripeTestHelpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** A trial that ends unpaid confines the tenant to billing and data export; its data is untouched and paying reopens it. */
class ExpiredTenantAccessTest extends TestCase
{
    use IsolatesDatabase;
    use StripeTestHelpers;

    private function expire(string $slug = 'acme'): Tenant
    {
        $tenant = Tenant::where('slug', $slug)->firstOrFail();
        $sub = app(SubscriptionService::class)->startTrial($tenant, $this->plan('pro'), 14);
        $sub->update(['status' => Subscription::STATUS_EXPIRED, 'trial_ends_at' => now()->subDay()]);
        app(TenantLifecycle::class)->transition($tenant->refresh(), Tenant::STATUS_EXPIRED, null, ['reason' => 'trial_expired']);

        return $tenant->refresh();
    }

    public function test_an_expired_tenant_can_sign_in_and_is_told_a_subscription_is_required(): void
    {
        $this->expire();

        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.subscription_required', true);
    }

    public function test_product_services_answer_402_while_billing_and_plans_stay_reachable(): void
    {
        $this->expire();
        $this->loginAdmin();

        foreach (['/api/workspaces', '/api/projects', '/api/dashboard', '/api/search/tasks?q=ab', '/api/users', '/api/reports/overview'] as $url) {
            $this->getJson($url)->assertStatus(402)
                ->assertJsonPath('code', 'subscription_required')
                ->assertHeader('X-Subscription-Required', 'true');
        }
        $this->postJson('/api/workspaces', ['name' => 'Nope'])->assertStatus(402);

        // what the tenant needs to subscribe, see its invoices and keep its own data
        $this->getJson('/api/my-subscription')->assertOk()->assertJsonPath('billing.subscription_required', true);
        $this->getJson('/api/plans')->assertOk();
        $this->getJson('/api/billing/history')->assertOk();
        $this->getJson('/api/billing/invoices')->assertOk();
        $this->getJson('/api/tenant/profile')->assertOk();
        $this->getJson('/api/notifications')->assertOk();
        $this->postJson('/api/auth/logout')->assertOk();
    }

    public function test_the_data_is_preserved_untouched(): void
    {
        $this->connectTenant('acme');
        $before = Workspace::count();
        $this->assertGreaterThanOrEqual(0, $before);
        $workspace = Workspace::create(['created_by' => 1, 'name' => 'Keep me', 'slug' => 'keep-me']);

        $this->expire();

        $this->connectTenant('acme');
        $this->assertTrue(Workspace::whereKey($workspace->id)->exists());
        $this->assertSame($before + 1, Workspace::count());
    }

    public function test_paying_reopens_the_tenant_immediately(): void
    {
        $tenant = $this->expire();
        $this->loginAdmin();
        $this->getJson('/api/workspaces')->assertStatus(402);

        // a paid invoice / completed checkout renews the subscription, which reactivates the tenant
        app(SubscriptionService::class)->renew($tenant->refresh());

        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->refresh()->status);
        $this->getJson('/api/workspaces')->assertOk();
        $this->getJson('/api/auth/me')->assertJsonPath('user.subscription_required', false);
    }

    public function test_other_blocked_states_still_cannot_sign_in(): void
    {
        $tenant = Tenant::where('slug', 'acme')->firstOrFail();
        app(TenantLifecycle::class)->transition($tenant, Tenant::STATUS_SUSPENDED);

        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertStatus(422);

        app(TenantLifecycle::class)->transition($tenant->refresh(), Tenant::STATUS_DEACTIVATED);
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertStatus(422);
    }

    public function test_an_active_tenant_and_the_platform_super_admin_are_unaffected(): void
    {
        $this->loginAdmin();
        $this->getJson('/api/workspaces')->assertOk();
        $this->postJson('/api/auth/logout');

        $this->expire('globex');
        $this->loginSuperAdmin();
        $this->getJson('/api/tenants')->assertOk();
    }

    public function test_the_card_less_trial_is_reminded_before_it_ends(): void
    {
        Mail::fake();
        $tenant = Tenant::where('slug', 'acme')->firstOrFail();
        app(SubscriptionService::class)->startTrial($tenant, $this->plan('pro'), 14);
        $tenant->refresh()->update(['trial_ends_at' => now()->addDays(2), 'billing_email' => 'billing@acme.test']);

        $this->artisan('billing:trial-reminders')->assertSuccessful();
        Mail::assertQueued(BillingNoticeMail::class, fn ($m) => $m->hasTo('billing@acme.test') && str_contains($m->mailSubject, 'trial'));

        // once per trial end date
        $this->artisan('billing:trial-reminders')->assertSuccessful();
        Mail::assertQueued(BillingNoticeMail::class, 3); // billing + contact(none) + admin, not a second round
    }
}
