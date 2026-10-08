<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    use IsolatesDatabase;

    public function test_admin_can_access_protected_resources(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'admin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $this->getJson('/api/users')->assertOk();
        $this->getJson('/api/roles')->assertOk();
    }

    public function test_user_without_permission_gets_403(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'viewer@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $this->connectTenant('acme');

        $viewer = User::where('email', 'viewer@flowsync.test')->first();
        $editor = User::where('email', 'editor@flowsync.test')->first();

        $this->getJson('/api/users')->assertForbidden();
        $this->putJson("/api/users/{$editor->id}/roles", ['roles' => ['admin']])->assertForbidden();
        $this->getJson('/api/roles')->assertForbidden();
    }

    /**
     * A denial names the grant it is waiting for. "This action is
     * unauthorized." told an admin to go hunting for a rule the sidebar had
     * already implied they held; the route middleware and the `permission`
     * gate are worded from one string (`EnsurePermission::denial`) so the two
     * paths can never drift apart.
     */
    public function test_a_permission_denial_names_the_missing_grant(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'viewer@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $this->connectTenant('acme');

        $viewer = User::where('email', 'viewer@flowsync.test')->first();

        $expected = 'The "users.view" permission is required for this action.';

        $this->getJson('/api/users')
            ->assertForbidden()
            ->assertJsonPath('message', $expected);

        $denied = Gate::forUser($viewer)->inspect('permission', 'users.view');

        $this->assertFalse($denied->allowed());
        $this->assertSame($expected, $denied->message());
    }

    public function test_viewer_can_customize_own_theme(): void
    {
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
