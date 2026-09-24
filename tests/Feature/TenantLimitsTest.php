<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\TenantLimits;
use App\Support\TenantContext;
use Illuminate\Validation\ValidationException;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class TenantLimitsTest extends TestCase
{
    use IsolatesDatabase;

    private TenantLimits $limits;

    protected function setUp(): void
    {
        parent::setUp();
        $this->limits = app(TenantLimits::class);
    }

    private function subscribe(string $slug, ?int $overrideUsers = null): void
    {
        $plan = SubscriptionPlan::where('slug', $slug)->firstOrFail();
        Subscription::create([
            'tenant_id' => $this->acme()->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        if ($overrideUsers !== null) {
            $this->acme()->update(['limits_override' => ['users' => $overrideUsers]]);
        }
    }

    public function test_no_subscription_means_unlimited(): void
    {
        $this->assertNull($this->limits->limit($this->acme(), 'users'));
        $this->assertTrue($this->limits->hasModule($this->acme(), 'reports'));

        app(TenantContext::class)->setTenantId($this->acme()->id);
        $this->limits->assertQuota('users'); // no throw
    }

    public function test_effective_merges_plan_limits_with_tenant_override(): void
    {
        $this->subscribe('starter', overrideUsers: 3);

        $effective = $this->limits->effective($this->acme()->refresh());
        $this->assertSame(3, $effective['users']);          // override wins
        $this->assertSame(10, $effective['projects']);      // from the plan
        $this->assertTrue($this->limits->hasModule($this->acme(), 'time_tracking'));
    }

    public function test_assert_quota_blocks_when_at_the_limit(): void
    {
        $this->subscribe('starter', overrideUsers: 1);
        app(TenantContext::class)->setTenantId($this->acme()->id);

        $this->expectException(ValidationException::class);
        $this->limits->assertQuota('users');
    }

    public function test_assert_quota_passes_under_the_limit(): void
    {
        $this->subscribe('starter', overrideUsers: 100);
        app(TenantContext::class)->setTenantId($this->acme()->id);

        $this->limits->assertQuota('users'); // acme has 4 users < 100
        $this->addToAssertionCount(1);
    }

    public function test_quota_is_not_enforced_without_tenant_context(): void
    {
        $this->subscribe('starter', overrideUsers: 0);

        app(TenantContext::class)->reset();
        $this->limits->assertQuota('users'); // context null → pass
        $this->addToAssertionCount(1);
    }

    public function test_workspace_and_project_and_task_custom_limits(): void
    {
        app(TenantContext::class)->setTenantId($this->acme()->id);

        $this->acme()->update([
            'limits_override' => ['workspaces' => 0, 'projects' => 0, 'tasks' => 0],
        ]);

        foreach (['workspaces', 'projects', 'tasks'] as $resource) {
            try {
                $this->limits->assertQuota($resource);
                $this->fail("Expected {$resource} quota to be blocked.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('form', $e->errors());
            }
        }
    }

    public function test_domain_creation_is_blocked_when_at_plan_limit(): void
    {
        // End-to-end: tenant admin at their workspace limit can't create a workspace.
        $this->postJson('/api/auth/login', [
            'email' => 'admin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $this->acme()->update(['limits_override' => ['workspaces' => 0]]);

        $this->postJson('/api/workspaces', [
            'name' => 'Over Limit Workspace',
        ])->assertUnprocessable()->assertJsonValidationErrors('form');
    }

    public function test_user_creation_is_blocked_when_at_plan_limit(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'admin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        // Acme has 4 users (owner admin/editor/viewer) — cap at that.
        $count = User::count();
        $this->acme()->update(['limits_override' => ['users' => $count]]);

        $this->postJson('/api/users', [
            'name' => 'Limit Test',
            'email' => 'limit@flowsync.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => ['viewer'],
        ])->assertUnprocessable()->assertJsonValidationErrors('form');
    }
}
