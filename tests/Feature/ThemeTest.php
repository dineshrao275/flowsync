<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class ThemeTest extends TestCase
{
    use IsolatesDatabase;

    private function loginAs(string $email): User
    {
        $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => 'password',
        ])->assertOk();

        $this->connectTenant('acme');

        return User::where('email', $email)->first();
    }

    public function test_theme_defaults_are_returned_when_admins_have_not_customized(): void
    {
        $admin = $this->loginAs('admin@flowsync.test');

        $this->getJson('/api/theme')
            ->assertOk()
            ->assertJsonPath('theme.sidebar_bg', config('theme.defaults.sidebar_bg'));
    }

    public function test_saved_theme_is_persisted_per_admin_and_returned_on_next_login(): void
    {
        $admin = $this->loginAs('admin@flowsync.test');

        $custom = [
            'sidebar_bg' => '#111111',
            'sidebar_hover' => '#222222',
            'active_menu' => '#333333',
            'sidebar_text' => '#aaaaaa',
            'dashboard_bg' => '#eeeeee',
            'header_bg' => '#cccccc',
            'header_text' => '#0f0f0f',
            'card_bg' => '#dddddd',
            'accent' => '#00ff00',
        ];

        $this->putJson('/api/theme', $custom)
            ->assertOk()
            ->assertJsonPath('theme.accent', '#00ff00');

        $this->postJson('/api/auth/logout')->assertOk();

        $this->postJson('/api/auth/login', [
            'email' => 'admin@flowsync.test',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('theme.accent', '#00ff00');

        $this->getJson('/api/theme')->assertOk()->assertJsonPath('theme.sidebar_bg', '#111111');
    }

    public function test_team_does_not_leak_between_admins(): void
    {
        $this->loginAs('admin@flowsync.test');

        $this->putJson('/api/theme', [
            'sidebar_bg' => '#ff0000',
            'sidebar_hover' => '#222222',
            'active_menu' => '#333333',
            'sidebar_text' => '#aaaaaa',
            'dashboard_bg' => '#eeeeee',
            'header_bg' => '#cccccc',
            'header_text' => '#0f0f0f',
            'card_bg' => '#dddddd',
            'accent' => '#00ff00',
        ])->assertOk();

        $this->postJson('/api/auth/logout')->assertOk();

        $this->loginAs('viewer@flowsync.test');

        $this->getJson('/api/theme')
            ->assertOk()
            ->assertJsonPath('theme.sidebar_bg', config('theme.defaults.sidebar_bg'));
    }

    public function test_invalid_colors_are_rejected(): void
    {
        $this->loginAs('admin@flowsync.test');

        $payload = array_fill_keys(array_keys(config('theme.defaults')), '#ffffff');
        $payload['sidebar_bg'] = 'red';

        $this->putJson('/api/theme', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sidebar_bg');
    }

    public function test_super_admin_theme_falls_back_to_defaults_without_persisting(): void
    {
        // `user_settings` is a tenant table, so a platform super admin must not
        // hit it — the payload echoes the theme and the defaults are returned.
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $this->getJson('/api/theme')
            ->assertOk()
            ->assertJsonPath('theme.sidebar_bg', config('theme.defaults.sidebar_bg'));

        $payload = array_fill_keys(array_keys(config('theme.defaults')), '#ffffff');
        $payload['accent'] = '#00ff00';

        $this->putJson('/api/theme', $payload)
            ->assertOk()
            ->assertJsonPath('theme.accent', '#00ff00');

        $this->getJson('/api/theme')
            ->assertOk()
            ->assertJsonPath('theme.accent', config('theme.defaults.accent'));
    }
}
