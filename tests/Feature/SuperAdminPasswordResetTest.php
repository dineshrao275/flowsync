<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SystemUser;
use App\Models\Tenant;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class SuperAdminPasswordResetTest extends TestCase
{
    use IsolatesDatabase;

    private Tenant $acme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->acme = Tenant::where('slug', 'acme')->first();
    }

    public function test_super_admin_cannot_request_password_reset_email(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'superadmin@flowsync.test',
        ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'If that address belongs to an account, a reset link is on its way.',
            ]);

        // Verify no notification was sent to superadmin
        Notification::assertNothingSent();

        // Verify no token was created in central system password_reset_tokens table
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'superadmin@flowsync.test',
        ], 'system');
    }

    public function test_tenant_user_can_request_password_reset(): void
    {
        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'admin@flowsync.test',
        ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'If that address belongs to an account, a reset link is on its way.',
            ]);

        // Token must exist in tenant database
        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => 'admin@flowsync.test',
        ]);
    }

    public function test_super_admin_cannot_reset_password_even_with_token(): void
    {
        $response = $this->postJson('/api/auth/reset-password', [
            'email' => 'superadmin@flowsync.test',
            'token' => 'some-token',
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'status' => Password::INVALID_USER,
            ]);
    }
}
