<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\TenantUserRouting;
use App\Support\TenantDatabaseManager;
use Illuminate\Support\Facades\Config;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use IsolatesDatabase;

    protected function enableRegistration(): void
    {
        Config::set('onboarding.enabled', true);
        // Item 10: the platform settings table is the primary gate (config is
        // only the seed-time fallback), so enable it explicitly.
        PlatformSetting::set('public_registration', '1');
    }

    public function test_register_is_disabled_by_default(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@newco.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'business_name' => 'Newco',
        ])->assertForbidden();

        $this->assertDatabaseMissing('tenants', ['slug' => 'newco'], 'iso_system');
    }

    public function test_register_creates_tenant_provisions_db_and_auto_logs_in(): void
    {
        $this->enableRegistration();

        $response = $this->postJson('/api/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@newco.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'business_name' => 'Newco Inc',
        ])->assertOk();

        $response->assertJsonPath('user.name', 'Jane Doe');
        $response->assertJsonPath('user.email', 'jane@newco.test');
        $response->assertJsonPath('user.onboarding_complete', false);

        $tenant = Tenant::where('slug', 'newco-inc')->firstOrFail();
        $this->assertTrue($tenant->isProvisioned());
        $this->assertTrue($tenant->isServiceable());

        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => $tenant->id,
            'email' => 'jane@newco.test',
        ], 'iso_system');

        $dbm = app(TenantDatabaseManager::class);
        $dbm->using($tenant, function (): void {
            $this->assertDatabaseHas('users', ['email' => 'jane@newco.test'], 'tenant');
            $this->assertDatabaseMissing('users', ['email' => 'owner@newco-inc.test'], 'tenant');
        });

        $this->assertSame(1, TenantUserRouting::where('tenant_id', $tenant->id)->count());
    }

    public function test_registered_tenant_domain_routes_gated_until_onboarding_complete(): void
    {
        $this->enableRegistration();

        $this->postJson('/api/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@newco.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'business_name' => 'Newco Inc',
        ])->assertOk();

        // Auto-logged in, but the domain is gated while onboarding is open.
        $this->getJson('/api/dashboard', ['plugins' => []])->assertForbidden();
        $this->getJson('/api/auth/me')->assertJsonPath('user.onboarding_complete', false);

        // The wizard endpoints themselves stay open.
        $this->getJson('/api/onboarding')
            ->assertOk()
            ->assertJsonPath('onboarding.overall', 'in_progress')
            ->assertJsonPath('onboarding.complete', false);

        // Partial progress keeps the gate closed (completion step still required).
        foreach (['business', 'admin', 'subscription'] as $step) {
            $this->putJson('/api/onboarding/step', ['step' => $step])->assertOk();
        }
        $this->getJson('/api/dashboard')->assertForbidden();

        // Finishing the wizard opens the domain.
        $this->postJson('/api/onboarding/complete')->assertOk();
        $this->getJson('/api/auth/me')->assertJsonPath('user.onboarding_complete', true);
        $this->getJson('/api/dashboard')->assertOk();
    }

    public function test_register_rejects_taken_or_super_admin_emails(): void
    {
        $this->enableRegistration();

        $payload = [
            'name' => 'Acme Cloner',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'business_name' => 'Clone Co',
        ];

        // Super admin email (central system users).
        $this->postJson('/api/register', [...$payload, 'email' => 'superadmin@flowsync.test'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        // A tenant-routed email.
        $this->postJson('/api/register', [...$payload, 'email' => 'jane@newco.test'])->assertOk();

        $this->postJson('/api/register', [...$payload, 'email' => 'jane@newco.test'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_register_rejects_invalid_slug_and_short_password(): void
    {
        $this->enableRegistration();

        $this->postJson('/api/register', [
            'name' => 'Jane',
            'email' => 'jane@newco.test',
            'password' => 'short',
            'password_confirmation' => 'short',
            'business_name' => 'Newco',
            'slug' => 'has spaces',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['password', 'slug']);
    }

    public function test_register_auto_suffixes_colliding_slug(): void
    {
        $this->enableRegistration();

        $this->postJson('/api/register', [
            'name' => 'Jane',
            'email' => 'jane@newco.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'business_name' => 'Acme',
        ])->assertOk();

        $slug = Tenant::orderByDesc('id')->first()->slug;
        $this->assertNotSame('acme', $slug);
        $this->assertNotEmpty($slug);
    }
}
