<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * P0.2 — four tenant routes used to answer any authenticated tenant user:
 * company profile write, the two onboarding writes, and payment history.
 */
class OpenRouteAuthorizationTest extends TestCase
{
    use IsolatesDatabase;

    private function startWizard(): void
    {
        Tenant::where('slug', 'acme')->firstOrFail()
            ->update(['onboarding_meta' => ['started_at' => now()->toISOString()]]);
    }

    public function test_a_non_admin_cannot_edit_the_company_profile(): void
    {
        $this->loginAs('editor@flowsync.test');

        $this->putJson('/api/tenant/profile', ['industry' => 'Hacked'])->assertForbidden();
    }

    public function test_an_admin_can_still_edit_the_company_profile(): void
    {
        $this->loginAs('admin@flowsync.test');

        $this->putJson('/api/tenant/profile', ['industry' => 'Software'])->assertOk();
    }

    public function test_a_non_admin_cannot_drive_the_onboarding_wizard(): void
    {
        $this->startWizard();
        $this->loginAs('viewer@flowsync.test');

        $this->putJson('/api/onboarding/step', ['step' => 'business'])->assertForbidden();
        $this->postJson('/api/onboarding/complete')->assertForbidden();
    }

    public function test_an_admin_can_still_drive_the_onboarding_wizard(): void
    {
        $this->startWizard();
        $this->loginAs('admin@flowsync.test');

        $this->putJson('/api/onboarding/step', ['step' => 'business'])->assertOk();
        $this->postJson('/api/onboarding/complete')->assertOk();
    }

    public function test_a_non_admin_cannot_read_payment_history(): void
    {
        $this->loginAs('editor@flowsync.test');

        $this->getJson('/api/billing/history')->assertForbidden();
    }

    public function test_an_admin_can_read_payment_history(): void
    {
        $this->loginAs('admin@flowsync.test');

        $this->getJson('/api/billing/history')->assertOk();
    }

    public function test_a_platform_super_admin_without_a_tenant_still_gets_404_not_403(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $this->putJson('/api/tenant/profile', ['industry' => 'x'])->assertNotFound();
        $this->getJson('/api/billing/history')->assertNotFound();
    }

    public function test_a_custom_role_with_tenant_manage_can_edit_the_profile_without_being_admin(): void
    {
        $this->connectTenant('acme');
        $role = Role::create(['name' => 'Org', 'slug' => 'org']);
        $role->permissions()->sync(Permission::where('slug', 'tenant.manage')->pluck('id'));
        $user = User::create(['name' => 'Org', 'email' => 'org@acme.test', 'password' => 'password']);
        $user->roles()->sync([$role->id]);
        $this->actingAs($user)->withSession(['login.tenant_id' => $this->acme()->id]);

        $this->putJson('/api/tenant/profile', ['industry' => 'Software'])->assertOk();
    }
}
