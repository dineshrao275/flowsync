<?php

namespace Tests\Feature;

use App\Models\ImpersonationLog;
use App\Models\PlatformSetting;
use App\Models\SupportAccessGrant;
use App\Models\User;
use App\Services\Security\SupportAccessGrants;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** P8.4 - tenant-granted, time-boxed support sessions built on the impersonation guard. */
class SupportAccessTest extends TestCase
{
    use IsolatesDatabase;

    private function grantAsAdmin(array $overrides = []): int
    {
        $this->loginAs('admin@flowsync.test');

        return $this->postJson('/api/support-access', $overrides + ['note' => 'Please look at the broken payroll run'])
            ->assertCreated()->json('grant.id');
    }

    private function startSession(array $extra = [])
    {
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => 'superadmin@flowsync.test', 'password' => 'password'])->assertOk();

        return $this->postJson('/api/impersonate', $extra + [
            'user_id' => User::where('email', 'viewer@flowsync.test')->value('id'),
            'tenant_id' => $this->acme()->id,
            'reason' => 'Ticket 4411 payroll run',
        ]);
    }

    public function test_a_tenant_admin_grants_lists_and_revokes_with_audit(): void
    {
        $id = $this->grantAsAdmin(['hours' => 2]);

        $this->getJson('/api/support-access')->assertOk()
            ->assertJsonPath('grants.0.id', $id)->assertJsonPath('grants.0.mode', 'read_only')->assertJsonPath('grants.0.active', true);
        $this->deleteJson("/api/support-access/{$id}")->assertOk()->assertJsonPath('grant.active', false);

        $this->assertDatabaseHas('audit_logs', ['action' => 'support_access.granted']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'support_access.revoked']);
    }

    public function test_grants_are_admin_only_capped_and_tenant_scoped(): void
    {
        $this->loginAs('viewer@flowsync.test');
        $this->getJson('/api/support-access')->assertForbidden();
        $this->postJson('/api/support-access', ['note' => 'a perfectly fine note'])->assertForbidden();

        $this->loginAs('admin@flowsync.test');
        $this->postJson('/api/support-access', ['note' => 'short'])->assertStatus(422);
        $this->postJson('/api/support-access', ['note' => 'a perfectly fine note', 'hours' => 9999])->assertStatus(422);

        $foreign = SupportAccessGrant::create([
            'tenant_id' => $this->globex()->id, 'granted_by_user_id' => 1, 'granted_by_name' => 'G', 'granted_by_email' => 'g@globex.test',
            'note' => 'globex only', 'expires_at' => now()->addDay(),
        ]);
        $this->deleteJson("/api/support-access/{$foreign->id}")->assertNotFound();
        $this->assertNull($foreign->fresh()->revoked_at);
        $this->getJson('/api/support-access')->assertJsonMissing(['note' => 'globex only']);
    }

    public function test_active_grants_are_capped_per_tenant(): void
    {
        $this->loginAs('admin@flowsync.test');
        for ($i = 0; $i < SupportAccessGrants::MAX_ACTIVE_PER_TENANT; $i++) {
            $this->postJson('/api/support-access', ['note' => "grant number {$i} here"])->assertCreated();
        }
        $this->postJson('/api/support-access', ['note' => 'one grant too many'])->assertStatus(422);
    }

    public function test_when_consent_is_required_impersonation_needs_an_active_grant(): void
    {
        PlatformSetting::set(SupportAccessGrants::POLICY_KEY, true);

        $this->startSession()->assertStatus(422)->assertJsonValidationErrors('grant_id');

        $id = $this->grantAsAdmin();
        $this->startSession(['grant_id' => $id])->assertOk()->assertJsonPath('user.impersonating', true);

        $log = ImpersonationLog::latest('id')->first();
        $this->assertSame($id, (int) $log->support_access_grant_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'support_access.used']);
        $this->assertSame(1, SupportAccessGrant::find($id)->uses);
    }

    public function test_the_grant_caps_mode_and_session_length(): void
    {
        $id = $this->grantAsAdmin(['session_minutes' => 10, 'hours' => 1]);

        $this->startSession(['grant_id' => $id, 'mode' => 'write'])->assertStatus(422)->assertJsonValidationErrors('mode');
        $this->startSession(['grant_id' => $id])->assertOk();

        $log = ImpersonationLog::latest('id')->first();
        $this->assertLessThanOrEqual(10 * 60 + 5, $log->expires_at->timestamp - now()->timestamp);
    }

    public function test_another_tenants_or_a_revoked_grant_is_refused(): void
    {
        $foreign = SupportAccessGrant::create([
            'tenant_id' => $this->globex()->id, 'granted_by_user_id' => 1, 'granted_by_name' => 'G', 'granted_by_email' => 'g@globex.test',
            'note' => 'globex only', 'expires_at' => now()->addDay(),
        ]);
        $this->startSession(['grant_id' => $foreign->id])->assertStatus(422)->assertJsonValidationErrors('grant_id');

        $id = $this->grantAsAdmin();
        SupportAccessGrant::find($id)->update(['revoked_at' => now()]);
        $this->startSession(['grant_id' => $id])->assertStatus(422)->assertJsonValidationErrors('grant_id');
    }

    public function test_revoking_ends_a_running_session_on_its_next_request(): void
    {
        $id = $this->grantAsAdmin();
        $this->startSession(['grant_id' => $id])->assertOk();
        $this->getJson('/api/auth/me')->assertOk();

        SupportAccessGrant::find($id)->update(['revoked_at' => now(), 'revoked_by_name' => 'Admin']);

        $this->getJson('/api/workspaces')->assertUnauthorized()->assertJsonPath('code', 'support_access_revoked');
    }

    public function test_support_cannot_manage_grants_while_impersonating(): void
    {
        $id = $this->grantAsAdmin();
        $this->startSession(['grant_id' => $id, 'mode' => 'write'])->assertOk();

        $this->postJson('/api/support-access', ['note' => 'self issued consent'])->assertForbidden();
        $this->deleteJson("/api/support-access/{$id}")->assertForbidden();
    }

    public function test_consent_policy_is_a_platform_setting_changed_by_settings_managers_only(): void
    {
        $this->loginAs('admin@flowsync.test');
        $this->putJson('/api/system/support-access/policy', ['consent_required' => true])->assertForbidden();

        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/login', ['email' => 'superadmin@flowsync.test', 'password' => 'password'])->assertOk();
        $this->putJson('/api/system/support-access/policy', ['consent_required' => true])->assertOk();
        $this->assertTrue(app(SupportAccessGrants::class)->consentRequired());
        $this->getJson('/api/system/support-access')->assertOk()->assertJsonPath('consent_required', true);
    }
}
