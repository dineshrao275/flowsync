<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Support\Totp;
use Illuminate\Testing\TestResponse;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** P8.1 - TOTP enrolment, the sign-in challenge, recovery codes, replay and enforce-per-role. */
class TwoFactorTest extends TestCase
{
    use IsolatesDatabase;

    private function login(string $email = 'admin@flowsync.test'): TestResponse
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password']);
    }

    /** @return array{0: string, 1: list<string>} secret + recovery codes */
    private function enroll(): array
    {
        $secret = $this->postJson('/api/auth/2fa/setup')->assertOk()->json('secret');
        $codes = $this->postJson('/api/auth/2fa/confirm', ['code' => Totp::code($secret)])
            ->assertOk()->assertJsonPath('enabled', true)->json('recovery_codes');

        return [$secret, $codes];
    }

    public function test_an_enrolled_user_must_pass_the_second_step(): void
    {
        $this->login()->assertOk();
        [$secret] = $this->enroll();
        $this->postJson('/api/auth/logout')->assertOk();

        $this->login()->assertOk()->assertJsonPath('two_factor_required', true);
        $this->getJson('/api/auth/me')->assertUnauthorized();

        $this->postJson('/api/auth/2fa/challenge', ['code' => '000000'])->assertStatus(422);
        $this->postJson('/api/auth/2fa/challenge', ['code' => Totp::code($secret, time() + 30)])
            ->assertOk()->assertJsonPath('user.email', 'admin@flowsync.test');
        $this->getJson('/api/auth/me')->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.2fa_enabled']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.2fa_failed']);
    }

    public function test_a_spent_code_cannot_be_replayed(): void
    {
        $this->login()->assertOk();
        [$secret] = $this->enroll();
        $this->postJson('/api/auth/logout');

        $code = Totp::code($secret, time() + 30);
        $this->login();
        $this->postJson('/api/auth/2fa/challenge', ['code' => $code])->assertOk();
        $this->postJson('/api/auth/logout');

        $this->login()->assertJsonPath('two_factor_required', true);
        $this->postJson('/api/auth/2fa/challenge', ['code' => $code])->assertStatus(422);
    }

    public function test_a_recovery_code_works_exactly_once(): void
    {
        $this->login()->assertOk();
        [, $codes] = $this->enroll();
        $this->postJson('/api/auth/logout');

        $this->login();
        $this->postJson('/api/auth/2fa/challenge', ['code' => $codes[0]])->assertOk();
        $this->postJson('/api/auth/logout');

        $this->login();
        $this->postJson('/api/auth/2fa/challenge', ['code' => $codes[0]])->assertStatus(422);
        $this->postJson('/api/auth/2fa/challenge', ['code' => $codes[1]])->assertOk();
    }

    public function test_five_wrong_codes_burn_the_pending_login(): void
    {
        $this->login()->assertOk();
        [$secret] = $this->enroll();
        $this->postJson('/api/auth/logout');

        $this->login();
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/auth/2fa/challenge', ['code' => '111111'])->assertStatus(422);
        }
        $this->postJson('/api/auth/2fa/challenge', ['code' => '111111'])->assertUnauthorized()->assertJsonPath('code', 'two_factor_expired');
        $this->postJson('/api/auth/2fa/challenge', ['code' => Totp::code($secret, time() + 30)])->assertUnauthorized();
    }

    public function test_the_challenge_without_a_password_step_is_refused(): void
    {
        $this->postJson('/api/auth/2fa/challenge', ['code' => '123456'])->assertUnauthorized();
    }

    public function test_disabling_needs_password_and_code(): void
    {
        $this->login()->assertOk();
        [$secret] = $this->enroll();

        $this->postJson('/api/auth/2fa/disable', ['password' => 'wrong', 'code' => Totp::code($secret, time() + 30)])->assertStatus(422);
        $this->postJson('/api/auth/2fa/disable', ['password' => 'password', 'code' => '000000'])->assertStatus(422);
        $this->postJson('/api/auth/2fa/disable', ['password' => 'password', 'code' => Totp::code($secret, time() + 30)])
            ->assertOk()->assertJsonPath('enabled', false);
    }

    public function test_a_role_that_requires_2fa_is_confined_to_enrolment_until_it_enrols(): void
    {
        $this->login()->assertOk();
        $this->putJson('/api/security/two-factor-policy', ['roles' => ['admin']])->assertOk();
        $this->putJson('/api/security/two-factor-policy', ['roles' => ['nope']])->assertStatus(422);
        $this->postJson('/api/auth/logout');

        $this->login()->assertOk()->assertJsonPath('user.two_factor.enrollment_required', true);
        $this->getJson('/api/workspaces')->assertForbidden()->assertJsonPath('code', 'two_factor_enrollment_required');
        $this->getJson('/api/auth/2fa')->assertOk()->assertJsonPath('required', true);

        [$secret] = $this->enroll();
        $this->getJson('/api/workspaces')->assertOk();

        $this->postJson('/api/auth/2fa/disable', ['password' => 'password', 'code' => Totp::code($secret, time() + 30)])
            ->assertStatus(422);
    }

    public function test_only_role_managers_edit_the_tenant_policy(): void
    {
        $this->login('viewer@flowsync.test')->assertOk();
        $this->putJson('/api/security/two-factor-policy', ['roles' => ['admin']])->assertForbidden();
        $this->getJson('/api/system/two-factor-policy')->assertForbidden();
    }

    public function test_platform_admins_can_be_required_to_enrol(): void
    {
        $this->login('superadmin@flowsync.test')->assertOk();
        $this->putJson('/api/system/two-factor-policy', ['require_super_admins' => true])->assertOk();
        $this->assertTrue(PlatformSetting::bool('require_2fa_super_admin'));
        $this->postJson('/api/auth/logout');

        $this->login('superadmin@flowsync.test')->assertOk()->assertJsonPath('user.two_factor.enrollment_required', true);
        $this->getJson('/api/system/settings')->assertForbidden()->assertJsonPath('code', 'two_factor_enrollment_required');

        $this->enroll();
        $this->getJson('/api/system/settings')->assertOk();
        $this->postJson('/api/auth/logout');

        $this->login('superadmin@flowsync.test')->assertJsonPath('two_factor_required', true);
    }
}
