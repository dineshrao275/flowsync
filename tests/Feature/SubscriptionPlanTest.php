<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use Database\Seeders\SubscriptionPlanSeeder;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class SubscriptionPlanTest extends TestCase
{
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginSuperAdmin();
    }

    private function loginSuperAdmin(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();
    }

    public function test_plans_are_seeded_from_the_catalog(): void
    {
        $plans = SubscriptionPlan::orderBy('sort_order')->get();

        $this->assertCount(3, $plans);
        $this->assertSame(['starter', 'pro', 'enterprise'], $plans->pluck('slug')->all());

        $starter = $plans->first();
        $this->assertTrue($starter->is_default);
        $this->assertTrue($starter->is_active);
        $this->assertSame(5, $starter->limit('users'));
        $this->assertTrue($starter->hasModule('time_tracking'));
    }

    public function test_super_admin_can_crud_plans(): void
    {
        $this->getJson('/api/plans')->assertOk()->assertJsonCount(3, 'plans');

        $create = $this->postJson('/api/plans', [
            'name' => 'Teams',
            'slug' => 'teams',
            'billing_cycle' => 'monthly',
            'price_cents' => 900,
            'trial_duration_days' => 7,
            'limits' => ['users' => 10, 'modules' => ['time_tracking']],
        ])->assertCreated()->assertJsonPath('plan.slug', 'teams');

        $this->assertDatabaseHas('subscription_plans', ['slug' => 'teams']);

        $id = $create->json('plan.id');

        $this->putJson("/api/plans/{$id}", [
            'name' => 'Teams Plus',
            'slug' => 'teams',
            'price_cents' => 1500,
        ])->assertOk()->assertJsonPath('plan.name', 'Teams Plus');

        $this->deleteJson("/api/plans/{$id}")->assertOk();
        $this->assertDatabaseMissing('subscription_plans', ['slug' => 'teams']);

        // Seeder is idempotent (catalog updates refresh existing rows, not dupes).
        (new SubscriptionPlanSeeder)->run();
        $this->assertCount(3, SubscriptionPlan::all());
    }

    public function test_super_admin_cannot_delete_the_default_plan(): void
    {
        $default = SubscriptionPlan::where('is_default', true)->firstOrFail();

        $this->deleteJson("/api/plans/{$default->id}")->assertUnprocessable();
    }

    public function test_cannot_delete_a_plan_in_use(): void
    {
        $pro = SubscriptionPlan::where('slug', 'pro')->firstOrFail();

        $this->postJson("/api/tenants/{$this->acme()->id}/subscription", [
            'plan_id' => $pro->id,
        ])->assertOk();

        $this->deleteJson("/api/plans/{$pro->id}")->assertUnprocessable();
        $this->assertDatabaseHas('subscription_plans', ['slug' => 'pro']);
    }

    public function test_tenant_admin_can_read_but_not_manage_plans(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'admin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        // Tenant-facing read (subscription self-service): the active catalog.
        $this->getJson('/api/plans')
            ->assertOk()
            ->assertJsonCount(3, 'plans');

        // Mutations remain super-admin-only.
        $this->postJson('/api/plans', [
            'name' => 'Sneaky',
            'slug' => 'sneaky',
        ])->assertForbidden();
    }
}
