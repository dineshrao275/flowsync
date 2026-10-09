<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use App\Services\TenantLimits;
use App\Support\TenantDatabaseManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** FB-4 — HRMS and TMS are sold, switched and limited as separate products. */
class ProductPlansTest extends TestCase
{
    use IsolatesDatabase;

    private function plan(string $slug): SubscriptionPlan
    {
        return SubscriptionPlan::where('slug', $slug)->firstOrFail();
    }

    private function acme(): Tenant
    {
        return Tenant::where('slug', 'acme')->firstOrFail();
    }

    private function limits(): TenantLimits
    {
        TenantLimits::resetMemo();

        return app(TenantLimits::class);
    }

    private function login(string $email): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
    }

    public function test_a_tenant_can_hold_one_plan_per_product_and_limits_come_from_the_right_plan(): void
    {
        $svc = app(SubscriptionService::class);
        $tenant = $this->acme();
        $svc->assign($tenant, $this->plan('tms-professional'));
        $svc->assign($tenant->refresh(), $this->plan('hrms-starter'));

        $this->assertSame(2, Subscription::where('tenant_id', $tenant->id)->count());

        $l = $this->limits()->effective($tenant->refresh());
        $this->assertSame(200, $l['projects']);          // from TMS Professional
        $this->assertSame(25, $l['employees']);          // from HRMS Starter
        $this->assertContains('reports', $l['modules']);
        $this->assertContains('hrms.core', $l['modules']);
        $this->assertNotContains('hrms.payroll', $l['modules']);
        $this->assertSame(50, $l['users']);              // the more generous of 50 and 25
    }

    public function test_a_tenant_with_only_an_hrms_plan_has_no_tms_and_the_server_says_so(): void
    {
        app(SubscriptionService::class)->assign($this->acme(), $this->plan('hrms-professional'));
        $limits = $this->limits();

        $this->assertTrue($limits->productEnabled($this->acme(), 'hrms'));
        $this->assertFalse($limits->productEnabled($this->acme(), 'tms'));
        $this->assertFalse($limits->hasModule($this->acme(), 'reports'));   // a TMS module follows its product

        $this->login('admin@flowsync.test');
        $this->getJson('/api/workspaces')->assertForbidden()->assertHeader('X-Product-Denied', 'tms');
        $this->getJson('/api/dashboard')->assertForbidden();
        $this->getJson('/api/hrms/employees')->assertOk();
    }

    public function test_a_tenant_with_only_a_tms_plan_has_no_hrms(): void
    {
        app(SubscriptionService::class)->assign($this->acme(), $this->plan('tms-starter'));

        $this->login('admin@flowsync.test');
        $this->getJson('/api/workspaces')->assertOk();
        $this->getJson('/api/hrms/employees')->assertForbidden();
    }

    public function test_the_legacy_bundle_still_covers_both_products(): void
    {
        app(SubscriptionService::class)->assign($this->acme(), $this->plan('pro'));
        $limits = $this->limits();

        $this->assertTrue($limits->productEnabled($this->acme(), 'tms'));
        $this->assertTrue($limits->productEnabled($this->acme(), 'hrms'));
    }

    public function test_a_bundle_and_per_product_plans_cannot_be_mixed_by_accident(): void
    {
        $svc = app(SubscriptionService::class);
        $svc->assign($this->acme(), $this->plan('pro'));

        $this->expectException(ValidationException::class);
        $svc->assign($this->acme()->refresh(), $this->plan('hrms-starter'));
    }

    public function test_splitting_a_bundle_moves_both_products_in_one_step(): void
    {
        $svc = app(SubscriptionService::class);
        $svc->assign($this->acme(), $this->plan('pro'));

        $svc->splitBundle($this->acme()->refresh(), [$this->plan('tms-professional'), $this->plan('hrms-professional')]);

        $tenant = $this->acme()->refresh();
        $this->assertSame('ended', $tenant->subscriptionFor('suite')->status);
        $this->assertSame('active', $tenant->subscriptionFor('tms')->status);
        $this->assertSame('active', $tenant->subscriptionFor('hrms')->status);
        $limits = $this->limits();
        $this->assertTrue($limits->productEnabled($tenant, 'tms') && $limits->productEnabled($tenant, 'hrms'));
    }

    public function test_the_super_admin_switches_a_product_off_and_back_to_the_plan(): void
    {
        $this->login('superadmin@flowsync.test');
        $acme = $this->acme();

        $this->putJson("/api/tenants/{$acme->id}/products", ['tms' => false, 'hrms' => null])->assertOk()
            ->assertJsonPath('products.tms', false)->assertJsonPath('products.hrms', true);
        $this->assertFalse($this->limits()->productEnabled($acme->refresh(), 'tms'));

        $this->putJson("/api/tenants/{$acme->id}/products", ['tms' => null, 'hrms' => null])->assertOk()
            ->assertJsonPath('products.tms', true)->assertJsonPath('products.override', null);
    }

    public function test_a_super_admin_can_split_through_the_api_and_the_listing_shows_both_subscriptions(): void
    {
        app(SubscriptionService::class)->assign($this->acme(), $this->plan('pro'));
        $this->login('superadmin@flowsync.test');
        $acme = $this->acme();

        $this->postJson("/api/tenants/{$acme->id}/subscription/split", [
            'tms_plan_id' => $this->plan('tms-enterprise')->id, 'hrms_plan_id' => $this->plan('hrms-starter')->id,
        ])->assertOk()->assertJsonCount(3, 'subscriptions');

        // A TMS plan id in the HRMS slot is refused.
        $this->postJson("/api/tenants/{$acme->id}/subscription/split", ['hrms_plan_id' => $this->plan('tms-starter')->id])->assertUnprocessable();
    }

    public function test_a_hand_made_tenant_can_start_on_one_plan_per_product(): void
    {
        $this->login('superadmin@flowsync.test');
        $id = $this->postJson('/api/tenants', ['name' => 'Split Co', 'slug' => 'split-co'])->assertCreated()->json('tenant.id');
        $this->putJson("/api/tenants/{$id}/intake", [
            'industry' => 'Software', 'company_size' => '11-50', 'country' => 'IN', 'billing_email' => 'b@split.test',
            'contact_name' => 'C', 'contact_email' => 'c@split.test', 'admin_name' => 'Ada', 'admin_email' => 'ada@split.test',
            'tms_plan_id' => $this->plan('tms-professional')->id, 'hrms_plan_id' => $this->plan('hrms-starter')->id, 'start_trial' => false,
        ])->assertOk()->assertJsonPath('intake.complete', true);

        $this->postJson("/api/tenants/{$id}/intake/submit", ['admin_password' => 'password123', 'admin_password_confirmation' => 'password123'])->assertStatus(202);

        $tenant = Tenant::findOrFail($id);
        $this->assertSame(['hrms', 'tms'], $tenant->subscriptions()->orderBy('product')->pluck('product')->all());
        $this->assertSame('tms', $tenant->subscription()->first()->product); // primary: TMS before HRMS
        $this->assertSame($tenant->subscription()->first()->id, $tenant->subscription_id);
    }

    public function test_a_bundle_and_a_product_plan_together_are_refused(): void
    {
        $this->login('superadmin@flowsync.test');
        $id = $this->postJson('/api/tenants', ['name' => 'Mixed Co', 'slug' => 'mixed-co'])->assertCreated()->json('tenant.id');
        $this->putJson("/api/tenants/{$id}/intake", [
            'industry' => 'Software', 'company_size' => '11-50', 'country' => 'IN', 'billing_email' => 'b@mixed.test',
            'contact_name' => 'C', 'contact_email' => 'c@mixed.test', 'admin_name' => 'Max', 'admin_email' => 'max@mixed.test',
            'plan_id' => $this->plan('pro')->id, 'hrms_plan_id' => $this->plan('hrms-starter')->id,
        ])->assertOk();

        $this->postJson("/api/tenants/{$id}/intake/submit", ['admin_password' => 'password123', 'admin_password_confirmation' => 'password123'])
            ->assertUnprocessable()->assertJsonValidationErrors('plan_id');
        $this->assertFalse(Tenant::findOrFail($id)->isProvisioned());
    }

    public function test_an_intake_needs_at_least_one_plan(): void
    {
        $this->login('superadmin@flowsync.test');
        $id = $this->postJson('/api/tenants', ['name' => 'Planless', 'slug' => 'planless'])->assertCreated()->json('tenant.id');

        $this->assertContains('plan_id', $this->getJson("/api/tenants/{$id}/intake")->json('intake.missing'));
    }

    public function test_the_session_payload_reports_which_products_the_tenant_has(): void
    {
        app(SubscriptionService::class)->assign($this->acme(), $this->plan('hrms-professional'));
        $this->login('admin@flowsync.test');

        $user = $this->getJson('/api/auth/me')->assertOk()->json('user');
        $this->assertSame(['tms' => false, 'hrms' => true], $user['products']);
        $this->assertContains('hrms.leave', $user['modules']);
        $this->assertNotContains('reports', $user['modules']);
    }

    /** FB-4 option B: the HRMS tables exist only for tenants that have the HRMS product. */
    public function test_a_tms_only_tenant_gets_no_hrms_tables_but_everything_core_works(): void
    {
        $this->login('superadmin@flowsync.test');
        $id = $this->postJson('/api/tenants', ['name' => 'Tasks Only', 'slug' => 'tasks-only'])->assertCreated()->json('tenant.id');
        $this->putJson("/api/tenants/{$id}/intake", [
            'industry' => 'Software', 'company_size' => '11-50', 'country' => 'IN', 'billing_email' => 'b@to.test',
            'contact_name' => 'C', 'contact_email' => 'c@to.test', 'admin_name' => 'Tess', 'admin_email' => 'tess@tasks-only.test',
            'tms_plan_id' => $this->plan('tms-starter')->id, 'start_trial' => false,
        ])->assertOk();
        $this->postJson("/api/tenants/{$id}/intake/submit", ['admin_password' => 'password123', 'admin_password_confirmation' => 'password123'])->assertStatus(202);
        $tenant = Tenant::findOrFail($id);

        $dbm = app(TenantDatabaseManager::class);
        $has = fn (string $table) => $dbm->using($tenant, fn () => Schema::hasTable($table));
        $this->assertTrue($has('tasks'));
        $this->assertTrue($has('users'));
        $this->assertFalse($has('employees'));
        $this->assertFalse($has('hrms_settings'));

        // Sign in and create a user: both used to touch `employees`.
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => 'tess@tasks-only.test', 'password' => 'password123'])->assertOk();
        $this->postJson('/api/users', ['name' => 'New Hire', 'email' => 'hire@tasks-only.test', 'password' => 'password123', 'password_confirmation' => 'password123', 'roles' => ['viewer']])->assertCreated();
        $this->getJson('/api/workspaces')->assertOk();
        $this->getJson('/api/hrms/employees')->assertForbidden();
    }

    public function test_enabling_hrms_later_builds_the_tables_and_gives_existing_logins_an_employee_record(): void
    {
        $this->login('superadmin@flowsync.test');
        $id = $this->postJson('/api/tenants', ['name' => 'Grows Later', 'slug' => 'grows-later'])->assertCreated()->json('tenant.id');
        $this->putJson("/api/tenants/{$id}/intake", [
            'industry' => 'Software', 'company_size' => '11-50', 'country' => 'IN', 'billing_email' => 'b@gl.test',
            'contact_name' => 'C', 'contact_email' => 'c@gl.test', 'admin_name' => 'Gus', 'admin_email' => 'gus@grows-later.test',
            'tms_plan_id' => $this->plan('tms-starter')->id, 'start_trial' => false,
        ])->assertOk();
        $this->postJson("/api/tenants/{$id}/intake/submit", ['admin_password' => 'password123', 'admin_password_confirmation' => 'password123'])->assertStatus(202);
        $tenant = Tenant::findOrFail($id);
        $dbm = app(TenantDatabaseManager::class);
        $count = fn () => $dbm->using($tenant, fn () => Schema::hasTable('employees')
            ? DB::table('employees')->count() : null);
        $this->assertNull($count());

        // The Super Admin adds an HRMS plan.
        $this->postJson("/api/tenants/{$id}/subscription", ['plan_id' => $this->plan('hrms-starter')->id])->assertOk();

        $this->assertSame(1, $count());   // the existing login now has an employment record
        $this->assertTrue($dbm->using($tenant, fn () => Schema::hasTable('hrms_settings')));
        $this->assertTrue(app(TenantLimits::class)->productEnabled($tenant->fresh(), 'hrms'));
    }
}
