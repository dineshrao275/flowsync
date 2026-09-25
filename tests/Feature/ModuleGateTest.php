<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SubscriptionService;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class ModuleGateTest extends TestCase
{
    use IsolatesDatabase;

    public function test_tenant_without_subscription_is_unlimited(): void
    {
        $this->login('admin@flowsync.test');

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.modules', config('subscriptions.modules'));

        $this->getJson('/api/reports/overview')->assertOk();
    }

    public function test_plan_modules_gate_domain_and_theme_routes(): void
    {
        $this->assignProToAcme();
        $this->login('admin@flowsync.test');

        $this->getJson('/api/reports/overview')->assertOk();
        $this->getJson('/api/search/tasks')->assertOk();
        $this->getJson('/api/search/global?q=acme')->assertOk();
        $this->getJson(sprintf('/api/workspaces/%s/time-summary', $this->workspace()->id))->assertOk();

        $payload = array_fill_keys(array_keys(config('theme.defaults')), '#123abc');
        $this->putJson('/api/theme', $payload)->assertForbidden();
    }

    public function test_removed_module_returns_403_on_its_routes(): void
    {
        $this->assignProToAcme();
        $this->setAcmeModules(['global_search']);
        $this->login('admin@flowsync.test');

        $this->getJson('/api/reports/overview')->assertForbidden();
        $this->getJson(sprintf('/api/workspaces/%s/time-summary', $this->workspace()->id))->assertForbidden();

        $this->getJson('/api/search/global?q=acme')->assertOk();
        $this->getJson('/api/search/tasks')->assertOk();
    }

    public function test_me_payload_reflects_effective_modules(): void
    {
        $this->assignProToAcme();
        $this->setAcmeModules(['reports']);
        $this->login('admin@flowsync.test');

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.modules', ['reports']);
    }

    public function test_non_impersonating_super_admin_bypasses_module_checks(): void
    {
        $this->login('superadmin@flowsync.test');

        $this->getJson('/api/search/global?q=acme')->assertOk();
    }

    private function login(string $email): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
    }

    private function workspace(): Workspace
    {
        $admin = User::where('email', 'admin@flowsync.test')->firstOrFail();

        return Workspace::firstOrCreate(
            ['slug' => 'acme-workspace'],
            ['name' => 'Acme Workspace', 'created_by' => $admin->id]
        );
    }

    private function assignProToAcme(): void
    {
        app(SubscriptionService::class)->assign($this->acme(), $this->proPlan());
    }

    private function setAcmeModules(array $modules): void
    {
        $plan = $this->proPlan();
        $plan->update(['limits' => array_merge($plan->limits, ['modules' => $modules])]);
    }

    private function proPlan(): SubscriptionPlan
    {
        return SubscriptionPlan::where('slug', 'pro')->firstOrFail();
    }
}
