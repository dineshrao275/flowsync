<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantDatabaseManager;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** FB-3 — a tenant is a draft with no database until every required field is in. */
class TenantIntakeTest extends TestCase
{
    use IsolatesDatabase;

    private function sa(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'superadmin@flowsync.test', 'password' => 'password'])->assertOk();
    }

    private function complete(array $over = []): array
    {
        return $over + [
            'industry' => 'Software',
            'company_size' => '11-50',
            'country' => 'in',
            'billing_email' => 'billing@intake.test',
            'contact_name' => 'Casey Contact',
            'contact_email' => 'casey@intake.test',
            'admin_name' => 'Dana Default',
            'admin_email' => 'Dana@Intake.test',
            'plan_id' => SubscriptionPlan::where('slug', 'pro')->value('id'),
            'start_trial' => true,
        ];
    }

    private function draft(): Tenant
    {
        $id = $this->postJson('/api/tenants', ['name' => 'Intake Co', 'slug' => 'intake-co'])
            ->assertCreated()->json('tenant.id');

        return Tenant::findOrFail($id);
    }

    public function test_a_draft_needs_only_a_name_and_a_slug_and_has_no_database(): void
    {
        $this->sa();
        $response = $this->postJson('/api/tenants', ['name' => 'Intake Co', 'slug' => 'intake-co'])->assertCreated();

        $response->assertJsonPath('tenant.status', 'draft')->assertJsonPath('intake.complete', false);
        $tenant = Tenant::where('slug', 'intake-co')->firstOrFail();
        $this->assertFalse($tenant->isProvisioned());
        $this->assertNull($tenant->db_name);
        $this->assertContains('industry', $response->json('intake.missing'));
        $this->assertContains('admin_email', $response->json('intake.missing'));
    }

    public function test_submit_refuses_until_every_required_field_is_present(): void
    {
        $this->sa();
        $tenant = $this->draft();
        $this->putJson("/api/tenants/{$tenant->id}/intake", ['industry' => 'Software', 'country' => 'IN'])->assertOk();

        $this->postJson("/api/tenants/{$tenant->id}/intake/submit", [
            'admin_password' => 'password123', 'admin_password_confirmation' => 'password123',
        ])->assertUnprocessable()->assertJsonValidationErrors(['company_size', 'billing_email', 'admin_email', 'plan_id']);

        $tenant->refresh();
        $this->assertSame('draft', $tenant->status);
        $this->assertFalse($tenant->isProvisioned());
    }

    public function test_a_complete_intake_provisions_the_tenant_with_its_default_user_and_a_14_day_trial(): void
    {
        $this->sa();
        $tenant = $this->draft();
        $this->putJson("/api/tenants/{$tenant->id}/intake", $this->complete())->assertOk()->assertJsonPath('intake.complete', true);

        $this->postJson("/api/tenants/{$tenant->id}/intake/submit", [
            'admin_password' => 'password123', 'admin_password_confirmation' => 'password123',
        ])->assertStatus(202);

        $tenant->refresh();
        $this->assertTrue($tenant->isProvisioned());
        $this->assertSame('trial', $tenant->status);
        $this->assertEquals(14, (int) now()->startOfDay()->diffInDays($tenant->trial_ends_at->startOfDay()));
        $this->assertSame('pro', $tenant->subscription->plan->slug);

        $default = app(TenantDatabaseManager::class)->using($tenant, fn () => User::defaultUser());
        $this->assertSame('dana@intake.test', $default->email);
        $this->assertSame('Dana Default', $default->name);

        // The show payload carries what the edit page displays.
        $this->getJson("/api/tenants/{$tenant->id}")
            ->assertOk()
            ->assertJsonPath('default_user.email', 'dana@intake.test')
            ->assertJsonPath('subscription.plan.slug', 'pro');

        // …and the new default user can really sign in.
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => 'dana@intake.test', 'password' => 'password123'])->assertOk();
    }

    public function test_no_trial_means_an_active_tenant(): void
    {
        $this->sa();
        $tenant = $this->draft();
        $this->putJson("/api/tenants/{$tenant->id}/intake", $this->complete(['start_trial' => false]))->assertOk();
        $this->postJson("/api/tenants/{$tenant->id}/intake/submit", [
            'admin_password' => 'password123', 'admin_password_confirmation' => 'password123',
        ])->assertStatus(202);

        $tenant->refresh();
        $this->assertSame('active', $tenant->status);
        $this->assertNull($tenant->trial_ends_at);
    }

    public function test_a_hand_made_tenant_is_exempt_from_the_card_rule_that_binds_self_service(): void
    {
        config(['onboarding.require_card_for_trial' => true]);
        $this->sa();
        $tenant = $this->draft();

        // A Super Admin cannot enter somebody else's card; the tenant adds one from billing.
        $this->putJson("/api/tenants/{$tenant->id}/intake", $this->complete())->assertOk()
            ->assertJsonPath('intake.complete', true);
    }

    public function test_a_taken_default_user_email_is_rejected(): void
    {
        $this->sa();
        $tenant = $this->draft();

        $this->putJson("/api/tenants/{$tenant->id}/intake", ['admin_email' => 'admin@flowsync.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('admin_email');
    }

    public function test_only_a_draft_can_be_edited_through_intake(): void
    {
        $this->sa();
        $acme = Tenant::where('slug', 'acme')->firstOrFail();

        $this->putJson("/api/tenants/{$acme->id}/intake", ['industry' => 'Retail'])
            ->assertUnprocessable()->assertJsonValidationErrors('form');
        $this->postJson("/api/tenants/{$acme->id}/intake/submit", [
            'admin_password' => 'password123', 'admin_password_confirmation' => 'password123',
        ])->assertUnprocessable();
    }

    public function test_intake_is_super_admin_only(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();

        $this->postJson('/api/tenants', ['name' => 'X', 'slug' => 'x'])->assertForbidden();
        $this->getJson('/api/tenants/1/intake')->assertForbidden();
    }
}
