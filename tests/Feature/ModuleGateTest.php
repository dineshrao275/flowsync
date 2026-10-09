<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureModule;
use App\Models\Hrms\Employee\Employee;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SubscriptionService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

        // Module denials are distinguishable from permission 403s so the SPA
        // can show the "not in your plan" surface instead of the generic one.
        $this->getJson('/api/reports/overview')
            ->assertForbidden()
            ->assertHeader('X-Module-Denied', 'reports');

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

    public function test_leave_routes_gate_on_the_hrms_leave_module(): void
    {
        $this->assignProToAcme();
        $this->setAcmeModules(['hrms.core']);
        $this->login('admin@flowsync.test');

        // HRMS routes resolve their tenant the same way every HRMS test
        // does: the operator connects the tenant explicitly, because these
        // routes sit outside the per-request switching group by design.
        $this->connectTenant('acme');

        $this->getJson('/api/hrms/leave/types')->assertForbidden();

        $this->setAcmeModules(['hrms.core', 'hrms.leave']);

        $this->getJson('/api/hrms/leave/types')->assertOk();
    }

    public function test_comp_off_routes_gate_on_the_hrms_comp_off_module(): void
    {
        $this->assignProToAcme();
        $this->setAcmeModules(['hrms.core']);
        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');

        $this->getJson('/api/hrms/comp-off/credits')->assertForbidden();

        $this->setAcmeModules(['hrms.core', 'hrms.comp_off']);

        // The tenant admin is a service account with no employment record;
        // give it one so the second leg proves the gate, not the 404.
        Employee::create([
            'employee_code' => 'EMP-GATE-1',
            'name' => 'Gatekeeper',
            'status' => 'active',
            'user_id' => User::where('email', 'admin@flowsync.test')->firstOrFail()->id,
        ]);

        $this->getJson('/api/hrms/comp-off/credits')->assertOk();
    }

    public function test_holiday_routes_gate_on_the_hrms_holidays_module(): void
    {
        $this->assignProToAcme();
        $this->setAcmeModules(['hrms.core']);
        $this->login('admin@flowsync.test');
        $this->connectTenant('acme');

        $this->getJson('/api/hrms/holidays/calendars')->assertForbidden();

        $this->setAcmeModules(['hrms.core', 'hrms.holidays']);

        $this->getJson('/api/hrms/holidays/calendars')->assertOk();
    }

    public function test_ensure_module_fails_closed_when_tenant_not_found(): void
    {
        $middleware = new EnsureModule;
        $request = Request::create('/api/reports/overview');

        app(TenantContext::class)->setTenantId(999999);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('A valid tenant context is required.');

        $middleware->handle($request, fn () => response()->noContent(), 'reports');
    }

    /**
     * Route groups stack two gates (`hrms.core` + a domain module). The two
     * middleware strings differ only by parameter, so a framework that
     * de-duplicated by class would silently keep just one of them.
     */
    public function test_stacked_module_gates_each_apply(): void
    {
        $this->assignProToAcme();
        $this->login('admin@flowsync.test');
        $url = '/api/hrms/expenses/categories';

        $this->setAcmeModules(['hrms.core']);
        $this->getJson($url)->assertForbidden()->assertHeader('X-Module-Denied', 'hrms.expenses');

        $this->setAcmeModules(['hrms.expenses']);
        $this->getJson($url)->assertForbidden()->assertHeader('X-Module-Denied', 'hrms.core');

        $this->setAcmeModules(['hrms.core', 'hrms.expenses']);
        $this->getJson($url)->assertOk();
    }

    /**
     * `hrms.onboarding`, `hrms.offboarding` and `hrms.documents` were sold in
     * plans but gated nothing (G-62): removing one from a plan changed no route.
     */
    public function test_onboarding_offboarding_and_documents_each_gate_their_own_routes(): void
    {
        $this->assignProToAcme();
        $this->login('admin@flowsync.test');

        $onboarding = '/api/hrms/onboarding/cases';
        $offboarding = '/api/hrms/offboarding/cases';
        $documents = '/api/hrms/documents';
        $requests = '/api/hrms/document-requests';

        $this->setAcmeModules(['hrms.core', 'hrms.offboarding', 'hrms.documents']);
        $this->getJson($onboarding)->assertForbidden()->assertHeader('X-Module-Denied', 'hrms.onboarding');
        $this->getJson($offboarding)->assertOk();
        $this->getJson($documents)->assertOk();
        $this->getJson($requests)->assertOk();

        $this->setAcmeModules(['hrms.core', 'hrms.onboarding', 'hrms.documents']);
        $this->getJson($onboarding)->assertOk();
        $this->getJson($offboarding)->assertForbidden()->assertHeader('X-Module-Denied', 'hrms.offboarding');

        $this->setAcmeModules(['hrms.core', 'hrms.onboarding', 'hrms.offboarding']);
        $this->getJson($documents)->assertForbidden()->assertHeader('X-Module-Denied', 'hrms.documents');
        $this->getJson($requests)->assertForbidden()->assertHeader('X-Module-Denied', 'hrms.documents');
        $this->getJson($onboarding)->assertOk();
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
