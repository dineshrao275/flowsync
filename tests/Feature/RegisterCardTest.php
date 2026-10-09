<?php

namespace Tests\Feature;

use App\Billing\Gateways\FakePaymentGateway;
use App\Billing\PaymentResolver;
use App\Models\PlatformSetting;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** FB-3/FB-6 — a self-service trial needs a card on file before any database exists. */
class RegisterCardTest extends TestCase
{
    use IsolatesDatabase;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        PlatformSetting::set('public_registration', '1');
        // Unauthenticated requests run on the central connection in production.
        DB::setDefaultConnection('iso_system');
        config(['onboarding.require_card_for_trial' => true]);
        $this->gateway = new FakePaymentGateway;
        app(PaymentResolver::class)->swapFake($this->gateway);
    }

    private function payload(array $over = []): array
    {
        return $over + [
            'name' => 'Cara Card', 'email' => 'cara@cardco.test', 'password' => 'password123', 'password_confirmation' => 'password123',
            'business_name' => 'Card Co', 'industry' => 'Software', 'company_size' => '11-50', 'country' => 'US',
            'plan_id' => SubscriptionPlan::where('slug', 'pro')->value('id'),
        ];
    }

    private function start(): array
    {
        $url = $this->postJson('/api/register/card', $this->payload())->assertOk()->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);

        return $q;
    }

    public function test_plain_register_refuses_a_trial_without_the_card_step(): void
    {
        $this->postJson('/api/register', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('form');
        $this->assertFalse(Tenant::where('slug', 'card-co')->exists());
    }

    public function test_the_card_step_parks_a_draft_without_creating_a_database(): void
    {
        $q = $this->start();

        $tenant = Tenant::where('slug', 'card-co')->firstOrFail();
        $this->assertSame('draft', $tenant->status);
        $this->assertFalse($tenant->isProvisioned());
        $this->assertNotEmpty($q['session_id']);
        $this->assertStringNotContainsString('password123', json_encode($tenant->onboarding_meta)); // hash only
    }

    public function test_completing_with_a_saved_card_provisions_logs_in_and_starts_the_provider_trial(): void
    {
        $q = $this->start();

        $this->postJson('/api/register/complete', ['session_id' => $q['session_id'], 't' => $q['t']])
            ->assertOk()->assertJsonPath('user.email', 'cara@cardco.test');

        $tenant = Tenant::where('slug', 'card-co')->firstOrFail();
        $this->assertTrue($tenant->isProvisioned());
        $this->assertSame('trial', $tenant->status);
        $this->assertCount(1, $this->gateway->trialSubscriptions);
        $this->assertSame(14, $this->gateway->trialSubscriptions[0]['trialDays']);
        $this->assertNotNull($tenant->subscription->provider_subscription_id);
        $this->assertArrayNotHasKey('password_hash', $tenant->onboarding_meta['intake']);
        $this->assertArrayNotHasKey('card_token', $tenant->onboarding_meta['intake']);

        // Replaying the link does nothing: the draft is gone.
        $this->postJson('/api/register/complete', ['session_id' => $q['session_id'], 't' => $q['t']])->assertNotFound();
    }

    public function test_an_unfinished_card_session_provisions_nothing(): void
    {
        $q = $this->start();
        $this->gateway->abandonCardSession($q['session_id']);

        $this->postJson('/api/register/complete', ['session_id' => $q['session_id'], 't' => $q['t']])->assertUnprocessable();

        $tenant = Tenant::where('slug', 'card-co')->firstOrFail();
        $this->assertSame('draft', $tenant->status);
        $this->assertFalse($tenant->isProvisioned());
    }

    public function test_a_session_from_another_signup_is_refused(): void
    {
        $q = $this->start();
        $this->postJson('/api/register/card', $this->payload(['email' => 'other@cardco.test', 'business_name' => 'Other Co']))->assertOk();
        $other = Tenant::where('slug', 'other-co')->firstOrFail();

        $this->postJson('/api/register/complete', ['session_id' => $other->onboarding_meta['intake']['card_session'], 't' => $q['t']])
            ->assertUnprocessable();
        $this->assertFalse(Tenant::where('slug', 'card-co')->firstOrFail()->isProvisioned());
    }

    public function test_options_report_whether_a_card_is_required(): void
    {
        $this->getJson('/api/register/options')->assertOk()->assertJsonPath('require_card_for_trial', true);
    }

    public function test_abandoned_drafts_are_pruned(): void
    {
        $this->start();
        $draft = Tenant::where('slug', 'card-co')->firstOrFail();
        $draft->forceFill(['updated_at' => now()->subDays(8)])->saveQuietly();
        $fresh = Tenant::create(['name' => 'Fresh', 'slug' => 'fresh-draft', 'status' => 'draft', 'provisioning_status' => 'pending']);

        Artisan::call('tenants:prune-drafts');

        $this->assertFalse(Tenant::withTrashed()->where('slug', 'card-co')->exists());
        $this->assertTrue(Tenant::where('slug', 'fresh-draft')->exists());
        $this->assertTrue(Tenant::where('slug', 'acme')->exists()); // real tenants are never touched
    }
}
