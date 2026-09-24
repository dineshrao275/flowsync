<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class TenantProfileTest extends TestCase
{
    use IsolatesDatabase;

    protected function loginSuperAdmin(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();
    }

    protected function loginTenantAdmin(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'admin@flowsync.test',
            'password' => 'password',
        ])->assertOk();
    }

    public function test_tenant_admin_cannot_update_tenant_or_its_profile(): void
    {
        $this->loginTenantAdmin();

        $this->connectTenant('acme');
        $tenantId = $this->acme()->id;

        $this->putJson("/api/tenants/{$tenantId}", ['name' => 'Acme Renamed'])->assertForbidden();
        $this->getJson("/api/tenants/{$tenantId}/profile")->assertForbidden();
        $this->putJson("/api/tenants/{$tenantId}/profile", ['industry' => 'Software'])->assertForbidden();
    }

    public function test_super_admin_can_update_tenant_name_and_slug(): void
    {
        $this->loginSuperAdmin();

        $this->connectTenant('acme');
        $tenantId = $this->acme()->id;

        $this->putJson("/api/tenants/{$tenantId}", [
            'name' => 'Acme Corporation',
            'slug' => 'acme-corp',
        ])->assertOk()->assertJsonPath('tenant.name', 'Acme Corporation')->assertJsonPath('tenant.slug', 'acme-corp');

        $this->assertDatabaseHas('tenants', ['id' => $tenantId, 'slug' => 'acme-corp'], 'iso_system');
        $this->assertEquals('Acme Corporation', Tenant::findOrFail($tenantId)->name);
    }

    public function test_super_admin_can_update_tenant_profile_fields(): void
    {
        $this->loginSuperAdmin();

        $this->connectTenant('acme');
        $tenantId = $this->acme()->id;

        $this->putJson("/api/tenants/{$tenantId}/profile", [
            'legal_name' => 'Acme Inc',
            'registration_number' => 'REG-1234',
            'tax_id' => 'US-99-1234567',
            'country' => 'US',
            'street' => '1 Main St',
            'city' => 'Springfield',
            'state' => 'IL',
            'postal_code' => '62701',
            'website' => 'https://acme.test',
            'industry' => 'Software',
            'company_size' => '51-200',
            'contact_phone' => '+1 555 0100',
            'billing_email' => 'billing@acme.test',
            'billing_address' => 'PO Box 123',
            'billing_currency' => 'USD',
            'timezone' => 'America/Chicago',
            'locale' => 'en',
            'brand_domain' => 'acme.app',
            'brand_logo_url' => 'https://acme.test/logo.png',
            'brand_primary_color' => '#6366f1',
        ])->assertOk();

        $this->assertDatabaseHas('tenants', [
            'id' => $tenantId,
            'legal_name' => 'Acme Inc',
            'tax_id' => 'US-99-1234567',
            'country' => 'US',
            'city' => 'Springfield',
            'billing_currency' => 'USD',
            'timezone' => 'America/Chicago',
            'brand_primary_color' => '#6366f1',
        ], 'iso_system');
    }

    public function test_super_admin_can_read_tenant_profile(): void
    {
        $this->loginSuperAdmin();

        $this->connectTenant('acme');
        $tenantId = $this->acme()->id;

        $this->getJson("/api/tenants/{$tenantId}/profile")
            ->assertOk()
            ->assertJsonPath('tenant.id', $tenantId)
            ->assertJsonPath('tenant.slug', 'acme');
    }

    public function test_tenant_admin_can_read_own_tenant_profile(): void
    {
        $this->loginTenantAdmin();

        $this->getJson('/api/tenant/profile')
            ->assertOk()
            ->assertJsonPath('tenant.slug', 'acme');
    }

    public function test_super_admin_without_tenant_context_cannot_read_self_profile(): void
    {
        $this->loginSuperAdmin();

        $this->getJson('/api/tenant/profile')->assertNotFound();
    }

    public function test_update_requires_valid_profile_values(): void
    {
        $this->loginSuperAdmin();

        $this->connectTenant('acme');
        $tenantId = $this->acme()->id;

        $this->putJson("/api/tenants/{$tenantId}/profile", [
            'country' => 'United States',
            'website' => 'not-a-url',
            'billing_currency' => 'USDOLLARS',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['country', 'website', 'billing_currency']);
    }

    public function test_update_rejects_duplicate_slug(): void
    {
        $this->loginSuperAdmin();

        $this->connectTenant('acme');
        $tenantId = $this->acme()->id;
        $globexId = $this->globex()->id;

        $this->putJson("/api/tenants/{$tenantId}", [
            'name' => 'Acme',
            'slug' => 'globex',
        ])->assertUnprocessable()->assertJsonValidationErrors('slug');

        $this->assertNotEquals('globex', Tenant::find($tenantId)->slug);
        $this->assertEquals('globex', Tenant::find($globexId)->slug);
    }
}
