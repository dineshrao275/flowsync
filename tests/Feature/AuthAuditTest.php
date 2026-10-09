<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\SystemUser;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/**
 * G-6: login/logout are identity events and land in the central audit feed.
 *
 * The asymmetry is deliberate and pinned here: `auth.*` rows keep the account
 *
 * email verbatim (an audit row that read `j***@***` could not answer who
 * signed in) while every secret stays out — see `AuditMask::maskSecrets()`.
 * The actor column is only fillable by a super admin session, because
 * `audit_logs.actor_id` is a FK to the central `users`.
 */
class AuthAuditTest extends TestCase
{
    use IsolatesDatabase;

    public function test_a_tenant_login_records_the_account_and_tenant(): void
    {
        // Captured before the login request: that request's `connectSystem()`
        // leaves the in-process default connection on `system`, so a lookup
        // after the fact would miss the tenant `users` table entirely.
        $userId = User::where('email', 'admin@flowsync.test')->firstOrFail()->id;

        $this->postJson('/api/auth/login', [
            'email' => 'admin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $log = AuditLog::where('action', 'auth.login')->firstOrFail();

        $this->assertSame('users', $log->subject_type);
        $this->assertSame('admin@flowsync.test', $log->data['email']);
        $this->assertSame($this->acme()->id, $log->data['tenant_id']);
        $this->assertSame('password', $log->data['via']);
        $this->assertArrayNotHasKey('password', $log->data);
        $this->assertNull($log->actor_id);
        $this->assertNotNull($log->ip_address);

        $this->assertSame($userId, $log->data['user_id']);
    }

    public function test_a_super_admin_login_names_the_actor(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $log = AuditLog::where('action', 'auth.login')->firstOrFail();

        $this->assertSame('superadmin@flowsync.test', $log->data['email']);
        $this->assertNull($log->data['tenant_id'] ?? null);
        $this->assertSame(SystemUser::where('email', 'superadmin@flowsync.test')->first()->id, $log->actor_id);
        $this->assertSame($log->actor_id, $log->subject_id);
    }

    public function test_a_failed_login_is_recorded_without_secrets(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'admin@flowsync.test',
            'password' => 'wrong-password',
        ])->assertUnprocessable();

        $log = AuditLog::where('action', 'auth.login_failed')->firstOrFail();

        $this->assertSame('admin@flowsync.test', $log->data['email']);
        $this->assertSame($this->acme()->id, $log->data['tenant_id']);
        $this->assertSame('password', $log->data['via']);
        $this->assertArrayNotHasKey('password', $log->data);
        $this->assertNull($log->actor_id);
    }

    public function test_a_failed_login_for_an_unknown_email_is_recorded(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable();

        $log = AuditLog::where('action', 'auth.login_failed')->firstOrFail();

        $this->assertSame('nobody@example.com', $log->data['email']);
        $this->assertNull($log->subject_id);
    }

    public function test_logout_is_recorded_with_the_tenant_it_left(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'admin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $this->postJson('/api/auth/logout')->assertOk();

        $log = AuditLog::where('action', 'auth.logout')->firstOrFail();

        $this->assertSame('admin@flowsync.test', $log->data['email']);
        $this->assertSame($this->acme()->id, $log->data['tenant_id']);
        $this->assertArrayNotHasKey('password', $log->data);
    }

    public function test_a_registration_session_is_labelled_as_such(): void
    {
        Config::set('onboarding.enabled', true);
        PlatformSetting::set('public_registration', '1');

        $this->postJson('/api/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@newco.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'business_name' => 'Newco Inc',
            'industry' => 'Software',
            'company_size' => '11-50',
            'country' => 'US',
        ])->assertOk();

        $log = AuditLog::where('action', 'auth.login')->firstOrFail();

        $this->assertSame('registration', $log->data['via']);
        $this->assertSame('jane@newco.test', $log->data['email']);
        $this->assertNotNull($log->data['tenant_id']);
    }
}
