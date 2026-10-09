<?php

namespace Tests\Feature;

use App\Models\ImpersonationLog;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * R7/R8 — impersonation needs a reason, is read-only by default, refuses the
 * actions that mint access or move money, and ends on its own.
 */
class ImpersonationHardeningTest extends TestCase
{
    use IsolatesDatabase;

    private int $targetId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectTenant('acme');
        $this->targetId = (int) User::where('email', 'admin@flowsync.test')->value('id');
    }

    private function start(array $overrides = []): TestResponse
    {
        $this->postJson('/api/auth/login', ['email' => 'superadmin@flowsync.test', 'password' => 'password'])->assertOk();

        return $this->postJson('/api/impersonate', $overrides + [
            'user_id' => $this->targetId,
            'tenant_id' => $this->acme()->id,
            'reason' => 'Ticket #4821 — checking the leave balance report',
        ]);
    }

    public function test_a_reason_is_required(): void
    {
        $this->start(['reason' => ''])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->start(['reason' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_the_reason_mode_and_expiry_are_recorded(): void
    {
        $this->start(['mode' => 'write'])->assertOk()
            ->assertJsonPath('user.impersonation_mode', 'write');

        $log = ImpersonationLog::latest('id')->firstOrFail();
        $this->assertSame('Ticket #4821 — checking the leave balance report', $log->reason);
        $this->assertSame('write', $log->mode);
        $this->assertTrue($log->expires_at->isFuture());
    }

    public function test_a_session_is_read_only_by_default(): void
    {
        $this->start()->assertOk()->assertJsonPath('user.impersonation_mode', 'read_only');

        $this->getJson('/api/workspaces')->assertOk();
        $this->putJson('/api/notification-preferences', ['preferences' => []])
            ->assertForbidden()->assertJsonPath('code', 'impersonation_read_only');
    }

    public function test_a_write_session_can_write_but_not_touch_access_or_billing(): void
    {
        $this->start(['mode' => 'write'])->assertOk();

        $this->putJson('/api/notification-preferences', ['preferences' => ['task.assigned' => false]])->assertOk();

        $this->postJson('/api/users', ['name' => 'X', 'email' => 'x@acme.test', 'password' => 'password123', 'password_confirmation' => 'password123', 'roles' => ['viewer']])
            ->assertForbidden()->assertJsonPath('code', 'impersonation_blocked');
        $this->postJson('/api/roles', ['name' => 'R', 'slug' => 'r', 'permissions' => []])
            ->assertForbidden()->assertJsonPath('code', 'impersonation_blocked');
        $this->postJson('/api/my-subscription/cancel')
            ->assertForbidden()->assertJsonPath('code', 'impersonation_blocked');
        $this->putJson('/api/tenant/profile', ['industry' => 'x'])
            ->assertForbidden()->assertJsonPath('code', 'impersonation_blocked');
    }

    public function test_stopping_is_always_allowed(): void
    {
        $this->start()->assertOk();

        $this->postJson('/api/impersonate/stop')->assertOk()
            ->assertJsonPath('user.impersonating', false);
    }

    public function test_an_expired_session_is_ended_on_the_next_request(): void
    {
        $this->start()->assertOk();

        $this->travel((int) config('tenancy.impersonation.ttl_minutes') + 1)->minutes();

        $this->getJson('/api/workspaces')->assertUnauthorized()->assertJsonPath('code', 'impersonation_expired');

        $log = ImpersonationLog::latest('id')->firstOrFail();
        $this->assertNotNull($log->ended_at);
        $this->assertTrue($log->ended_at->equalTo($log->expires_at));
    }

    public function test_the_sweeper_closes_sessions_nobody_touched_again(): void
    {
        $this->start()->assertOk();
        $log = ImpersonationLog::latest('id')->firstOrFail();
        $this->assertNull($log->ended_at);

        $this->travel((int) config('tenancy.impersonation.ttl_minutes') + 1)->minutes();
        $this->artisan('tenants:close-impersonations')->assertSuccessful();

        $this->assertNotNull($log->fresh()->ended_at);
    }
}
