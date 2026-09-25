<?php

namespace Tests\Feature;

use Tests\IsolatesDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use IsolatesDatabase;

    public function test_guest_cannot_access_me(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_user_can_login_and_logout(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'admin@flowsync.test',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('user.email', 'admin@flowsync.test')
            ->assertJsonPath('user.roles.0', 'admin')
            ->assertJsonPath('user.tenant.slug', 'acme');

        $this->getJson('/api/auth/me')->assertOk();

        $this->postJson('/api/auth/logout')->assertOk();
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_super_admin_can_login_without_tenant(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('user.is_super_admin', true)
            ->assertJsonPath('user.tenant', null);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }
}
