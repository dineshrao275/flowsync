<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_protected_resources(): void
    {
        $this->seed(TenantSeeder::class);
        $this->postJson('/api/auth/login', [
            'email' => 'admin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $this->getJson('/api/users')->assertOk();
        $this->getJson('/api/roles')->assertOk();
    }

    public function test_user_without_permission_gets_403(): void
    {
        $this->seed(TenantSeeder::class);
        $this->postJson('/api/auth/login', [
            'email' => 'viewer@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $viewer = User::where('email', 'viewer@flowsync.test')->first();
        $editor = User::where('email', 'editor@flowsync.test')->first();

        $this->getJson('/api/users')->assertForbidden();
        $this->putJson("/api/users/{$editor->id}/roles", ['roles' => ['admin']])->assertForbidden();
        $this->getJson('/api/roles')->assertForbidden();
    }

    public function test_viewer_can_customize_own_theme(): void
    {
        $this->seed(TenantSeeder::class);
        $this->postJson('/api/auth/login', [
            'email' => 'viewer@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $payload = array_fill_keys(array_keys(config('theme.defaults')), '#123abc');

        $this->putJson('/api/theme', $payload)->assertOk();
    }

    public function test_unauthenticated_request_to_protected_route_gets_401(): void
    {
        $this->getJson('/api/theme')->assertUnauthorized();
    }

    public function test_unauthenticated_browser_request_gets_json_401_not_redirect(): void
    {
        $this->get('/api/theme')
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);
    }
}
