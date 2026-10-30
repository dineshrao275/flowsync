<?php

namespace Tests\Feature;

use App\Billing\Stripe\BillingProviderException;
use App\Billing\Stripe\CatalogSync;
use App\Jobs\ArchiveStripePlan;
use App\Jobs\SyncPlanToStripe;
use App\Models\SubscriptionPlan;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\StripeTestHelpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** Stripe products/prices/customers mirror the plan catalog; only the super admin changes it. */
class StripeCatalogSyncTest extends TestCase
{
    use IsolatesDatabase;
    use StripeTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureStripe();
    }

    private function fakeStripe(): void
    {
        Http::fake([
            'api.stripe.com/v1/products/*' => Http::response(['id' => 'prod_1']),
            'api.stripe.com/v1/products' => Http::response(['id' => 'prod_1']),
            'api.stripe.com/v1/prices/*' => Http::response(['id' => 'price_x']),
            'api.stripe.com/v1/prices' => Http::sequence()->push(['id' => 'price_1'])->push(['id' => 'price_2'])->push(['id' => 'price_3']),
            'api.stripe.com/v1/customers*' => Http::response(['id' => 'cus_1']),
        ]);
    }

    public function test_a_plan_gets_one_product_and_one_price_and_a_resync_changes_nothing(): void
    {
        $this->fakeStripe();
        $plan = $this->plan('pro');

        app(CatalogSync::class)->syncPlan($plan);
        $plan->refresh();

        $this->assertSame('prod_1', $plan->stripe_product_id);
        $this->assertSame('price_1', $plan->stripe_price_id);
        $this->assertNotNull($plan->stripe_synced_at);
        $this->assertNull($plan->stripe_sync_error);

        $created = $this->stripePosts('/prices');
        $this->assertCount(1, $created);
        $this->assertSame('prod_1', $created[0]->data()['product']);
        $this->assertSame('2900', (string) $created[0]->data()['unit_amount']);
        $this->assertSame('month', $created[0]->data()['recurring[interval]']);

        // Every write carries an idempotency key (a retry cannot create a duplicate).
        Http::assertSent(fn (HttpRequest $r) => $r->method() !== 'POST' || $r->hasHeader('Idempotency-Key'));

        app(CatalogSync::class)->syncPlan($plan->fresh());
        $this->assertCount(1, $this->stripePosts('/prices')); // price unchanged: not recreated
    }

    public function test_changing_the_amount_creates_a_new_price_makes_it_the_default_and_archives_the_old_one(): void
    {
        $this->fakeStripe();
        $plan = $this->plan('pro');
        $sync = app(CatalogSync::class);
        $sync->syncPlan($plan);

        $plan->update(['price_cents' => 3500]);
        $sync->syncPlan($plan->fresh());

        $this->assertSame('price_2', $plan->fresh()->stripe_price_id);
        $this->assertCount(2, $this->stripePosts('/prices'));
        $defaults = collect($this->stripePosts('/products/prod_1'))->filter(fn ($r) => ($r->data()['default_price'] ?? null) === 'price_2');
        $this->assertCount(1, $defaults);
        $archived = collect($this->stripePosts('/prices/price_1'))->filter(fn ($r) => ($r->data()['active'] ?? null) === 'false');
        $this->assertCount(1, $archived);
    }

    public function test_reverting_to_an_earlier_amount_creates_a_fresh_price_instead_of_replaying_the_archived_one(): void
    {
        $this->fakeStripe();
        $plan = $this->plan('pro');
        $sync = app(CatalogSync::class);
        $sync->syncPlan($plan);                              // 2900 -> price_1
        $plan->update(['price_cents' => 3400]);
        $sync->syncPlan($plan->fresh());                     // 3400 -> price_2
        $plan->update(['price_cents' => 2900]);
        $sync->syncPlan($plan->fresh());                     // back to 2900 -> price_3 (a new one)

        $this->assertSame('price_3', $plan->fresh()->stripe_price_id);
        $keys = collect($this->stripePosts('/prices'))->map(fn ($r) => $r->header('Idempotency-Key')[0])->all();
        $this->assertCount(3, array_unique($keys)); // three distinct creations, so Stripe cannot replay an old response
    }

    public function test_a_deactivated_plan_is_archived_at_stripe_and_comes_back_when_reactivated(): void
    {
        $this->fakeStripe();
        $plan = $this->plan('pro');
        $sync = app(CatalogSync::class);
        $sync->syncPlan($plan);

        $plan->update(['is_active' => false]);
        $sync->syncPlan($plan->fresh());
        $this->assertNotEmpty(collect($this->stripePosts('/products/prod_1'))->filter(fn ($r) => ($r->data()['active'] ?? null) === 'false'));
        $this->assertNotEmpty(collect($this->stripePosts('/prices/price_1'))->filter(fn ($r) => ($r->data()['active'] ?? null) === 'false'));

        $plan->update(['is_active' => true]);
        $sync->syncPlan($plan->fresh());
        $this->assertNotEmpty(collect($this->stripePosts('/prices/price_1'))->filter(fn ($r) => ($r->data()['active'] ?? null) === 'true'));
        $this->assertCount(1, $this->stripePosts('/prices')); // reused, not recreated
    }

    public function test_a_free_plan_never_reaches_stripe_and_an_unconfigured_install_is_a_no_op(): void
    {
        $this->fakeStripe();
        app(CatalogSync::class)->syncPlan($this->plan('starter'));
        Http::assertNothingSent();

        config(['payments.gateways.stripe.secret' => '']);
        app(CatalogSync::class)->syncPlan($this->plan('pro'));
        Http::assertNothingSent();
    }

    public function test_a_stripe_outage_is_retried_then_recorded_on_the_plan(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'boom']], 503)]);
        $plan = $this->plan('pro');

        try {
            app(CatalogSync::class)->syncPlan($plan);
            $this->fail('expected a provider exception');
        } catch (BillingProviderException $e) {
            $this->assertTrue($e->retryable);
        }

        Http::assertSentCount(3); // bounded retries
        $this->assertStringContainsString('503', (string) $plan->fresh()->stripe_sync_error);
        $this->assertNull($plan->fresh()->stripe_synced_at);
    }

    public function test_a_rejected_request_is_not_retried(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'bad', 'code' => 'parameter_invalid']], 400)]);

        try {
            app(CatalogSync::class)->syncPlan($this->plan('pro'));
            $this->fail('expected a provider exception');
        } catch (BillingProviderException $e) {
            $this->assertFalse($e->retryable);
        }

        Http::assertSentCount(1);
    }

    public function test_the_super_admin_editing_a_plan_queues_the_sync_and_tenants_cannot_edit_plans(): void
    {
        Bus::fake([SyncPlanToStripe::class, ArchiveStripePlan::class]);
        $plan = $this->plan('pro');

        // a tenant admin may read the active plans but not change them
        $this->loginAdmin();
        $listed = $this->getJson('/api/plans')->assertOk()->json('plans.0');
        $this->assertArrayNotHasKey('stripe_price_id', $listed);
        $this->assertArrayNotHasKey('stripe_sync_error', $listed);
        $this->putJson("/api/plans/{$plan->id}", ['name' => 'Hacked', 'slug' => 'pro', 'price_cents' => 1])->assertForbidden();
        $this->postJson('/api/auth/logout');

        $this->loginSuperAdmin();
        $this->putJson("/api/plans/{$plan->id}", ['name' => 'Pro', 'slug' => 'pro', 'description' => 'Updated copy'])->assertOk();
        Bus::assertDispatched(SyncPlanToStripe::class, fn ($job) => $job->plan->is($plan));

        $created = $this->postJson('/api/plans', ['name' => 'Team', 'slug' => 'team-x', 'price_cents' => 1500, 'billing_cycle' => 'monthly'])->assertCreated();
        Bus::assertDispatched(SyncPlanToStripe::class, fn ($job) => $job->plan->id === $created->json('plan.id'));
    }

    public function test_the_manual_resync_button_syncs_one_plan_for_the_super_admin_only(): void
    {
        // Found by the form audit: POST /api/plans/{plan}/sync had no HTTP-level test at all.
        $this->fakeStripe();
        $plan = $this->plan('pro');

        $this->loginAdmin();
        $this->postJson("/api/plans/{$plan->id}/sync")->assertForbidden();
        $this->postJson('/api/auth/logout');

        $this->loginSuperAdmin();
        $this->postJson("/api/plans/{$plan->id}/sync")->assertOk()->assertJsonPath('message', 'Plan synchronised with Stripe.');
        $this->assertSame('prod_1', $plan->fresh()->stripe_product_id);
        $this->assertSame('price_1', $plan->fresh()->stripe_price_id);

        // Without Stripe credentials the button explains itself instead of failing.
        config(['payments.gateways.stripe.secret' => '']);
        $this->app->forgetInstance(CatalogSync::class);
        $this->postJson("/api/plans/{$plan->id}/sync")->assertStatus(422);
    }

    public function test_deleting_a_plan_archives_what_it_left_at_stripe(): void
    {
        Bus::fake([SyncPlanToStripe::class, ArchiveStripePlan::class]);
        $this->loginSuperAdmin();
        $plan = SubscriptionPlan::create(['name' => 'Temp', 'slug' => 'temp-x', 'price_cents' => 900, 'billing_cycle' => 'monthly', 'currency' => 'USD', 'stripe_product_id' => 'prod_t', 'stripe_price_id' => 'price_t']);

        $this->deleteJson("/api/plans/{$plan->id}")->assertOk();

        Bus::assertDispatched(ArchiveStripePlan::class, fn ($job) => $job->productId === 'prod_t' && $job->priceId === 'price_t');
    }

    public function test_one_customer_per_tenant_is_named_after_the_organisation_and_kept_in_step(): void
    {
        $this->fakeStripe();
        $tenant = $this->acme();
        $sync = app(CatalogSync::class);

        $id = $sync->customerFor($tenant);
        $this->assertSame('cus_1', $id);
        $created = $this->stripePosts('/customers');
        $this->assertSame($tenant->name, $created[0]->data()['name']);
        $this->assertSame((string) $tenant->id, $created[0]->data()['metadata[tenant_id]']);

        // asking again never creates a second one
        $this->assertSame('cus_1', $sync->customerFor($tenant->fresh()));
        $this->assertCount(1, $this->stripePosts('/customers'));

        // renaming the organisation pushes the new name to the existing customer
        $tenant->fresh()->update(['name' => 'Acme Renamed Ltd']);
        $renamed = collect($this->stripePosts('/customers/cus_1'))->filter(fn ($r) => ($r->data()['name'] ?? null) === 'Acme Renamed Ltd');
        $this->assertCount(1, $renamed);
    }
}
