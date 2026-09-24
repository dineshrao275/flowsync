<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use IsolatesDatabase;

    protected function startWizard(string $slug): Tenant
    {
        $tenant = Tenant::where('slug', $slug)->firstOrFail();
        $tenant->update(['onboarding_meta' => ['started_at' => now()->toISOString()]]);

        return $tenant->fresh();
    }

    public function test_admin_provisioned_tenant_is_complete_without_starting_the_wizard(): void
    {
        $this->loginAs('admin@flowsync.test');

        // Acme was seed-provisioned (never started the wizard) → gate passes.
        $this->getJson('/api/onboarding')
            ->assertOk()
            ->assertJsonPath('onboarding.overall', 'pending')
            ->assertJsonPath('onboarding.complete', true);

        $this->getJson('/api/dashboard')->assertOk();
    }

    public function test_domain_is_403_while_wizard_started_then_200_when_complete(): void
    {
        $this->loginAs('admin@flowsync.test');
        $this->startWizard('acme');

        $this->getJson('/api/dashboard')->assertForbidden();
        $this->getJson('/api/auth/me')->assertJsonPath('user.onboarding_complete', false);

        // Mark a required step — still incomplete (terminal step remains).
        $this->putJson('/api/onboarding/step', ['step' => 'business'])
            ->assertOk()
            ->assertJsonPath('onboarding.steps.0.complete', true)
            ->assertJsonPath('onboarding.overall', 'in_progress');
        $this->getJson('/api/dashboard')->assertForbidden();

        // Finish the wizard → domain opens.
        $this->postJson('/api/onboarding/complete')
            ->assertOk()
            ->assertJsonPath('onboarding.overall', 'complete');
        $this->getJson('/api/auth/me')->assertJsonPath('user.onboarding_complete', true);
        $this->getJson('/api/dashboard')->assertOk();
    }

    public function test_super_admin_can_read_and_drive_tenant_onboarding(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $acme = $this->startWizard('acme');

        $this->getJson("/api/tenants/{$acme->id}/onboarding")
            ->assertOk()
            ->assertJsonPath('onboarding.complete', false);

        $this->putJson("/api/tenants/{$acme->id}/onboarding", ['step' => 'business'])
            ->assertOk()
            ->assertJsonPath('onboarding.steps.0.complete', true);

        $this->putJson("/api/tenants/{$acme->id}/onboarding", ['step' => 'reset'])
            ->assertOk()
            ->assertJsonPath('onboarding.overall', 'pending')
            ->assertJsonPath('onboarding.complete', true);

        $this->assertDatabaseHas('tenants', [
            'id' => $acme->id,
            'onboarding_meta' => null,
        ], 'iso_system');

        $this->putJson("/api/tenants/{$acme->id}/onboarding", ['step' => 'complete'])
            ->assertOk()
            ->assertJsonPath('onboarding.overall', 'complete');
    }

    public function test_tenant_admin_cannot_drive_another_tenants_onboarding(): void
    {
        $this->loginAs('admin@flowsync.test');
        $globex = Tenant::where('slug', 'globex')->firstOrFail();

        $this->getJson("/api/tenants/{$globex->id}/onboarding")->assertForbidden();
        $this->putJson("/api/tenants/{$globex->id}/onboarding", ['step' => 'complete'])->assertForbidden();
    }

    public function test_super_admin_bypasses_the_onboarding_gate_on_domain_routes(): void
    {
        // Super admin hitting their own domain routes: no tenant context → gate
        // bypasses (tenant_context already 403s, but the gate itself must not
        // turn a non-impersonating super admin into an onboarding blocker).
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $this->startWizard('acme');

        // A non-impersonating super admin is blocked by tenant_context (403),
        // not by the onboarding gate — assert the gate message is absent.
        $response = $this->getJson('/api/workspaces');
        $response->assertForbidden();
        $this->assertStringNotContainsString('onboarding', strtolower($response->json('message') ?? ''));
    }

    public function test_onboarding_rejects_unknown_step(): void
    {
        $this->loginAs('admin@flowsync.test');
        $this->startWizard('acme');

        $this->putJson('/api/onboarding/step', ['step' => 'nonexistent'])->assertUnprocessable();
        // The terminal step is not user-completable via markStep.
        $this->putJson('/api/onboarding/step', ['step' => 'completion'])->assertUnprocessable();
    }
}
