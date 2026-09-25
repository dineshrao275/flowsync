<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
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

    public function test_super_admin_theme_persists_in_platform_settings(): void
    {
        // `user_settings` is a tenant table, so a platform super admin's theme
        // is stored in the central key-value settings instead of being dropped.
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $this->getJson('/api/theme')
            ->assertOk()
            ->assertJsonPath('theme.accent', config('theme.defaults.accent'))
            ->assertJsonPath('theme.mode', config('theme.default_mode'));

        $payload = array_fill_keys(array_keys(config('theme.defaults')), '#ffffff');
        $payload['accent'] = '#00ff00';
        $payload['mode'] = 'dark';

        $this->putJson('/api/theme', $payload)
            ->assertOk()
            ->assertJsonPath('theme.accent', '#00ff00')
            ->assertJsonPath('theme.mode', 'dark');

        $this->getJson('/api/theme')
            ->assertOk()
            ->assertJsonPath('theme.accent', '#00ff00')
            ->assertJsonPath('theme.mode', 'dark');

        $this->assertTrue(
            PlatformSetting::query()->where('key', 'like', 'theme.user.%')->exists(),
            'A per-super-admin theme.user.<id> setting should exist.'
        );

        // The scheme survives a fresh session.
        $this->postJson('/api/auth/logout')->assertOk();
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('theme.accent', '#00ff00')
            ->assertJsonPath('theme.mode', 'dark');
    }

    public function test_super_admin_theme_does_not_leak_into_a_tenant(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $payload = array_fill_keys(array_keys(config('theme.defaults')), '#ffffff');
        $payload['accent'] = '#00ff00';
        $payload['mode'] = 'dark';
        $this->putJson('/api/theme', $payload)->assertOk();

        $this->postJson('/api/auth/logout')->assertOk();
        $this->loginAs('admin@flowsync.test');

        $this->getJson('/api/theme')
            ->assertOk()
            ->assertJsonPath('theme.accent', config('theme.defaults.accent'))
            ->assertJsonPath('theme.mode', config('theme.default_mode'));
    }

    public function test_color_scheme_is_persisted_and_returned(): void
    {
        $this->loginAs('admin@flowsync.test');

        $payload = array_fill_keys(array_keys(config('theme.defaults')), '#ffffff');
        $payload['mode'] = 'dark';

        $this->putJson('/api/theme', $payload)
            ->assertOk()
            ->assertJsonPath('theme.mode', 'dark');

        // Restored on the next login payload and on GET.
        $this->postJson('/api/auth/logout')->assertOk();
        $this->postJson('/api/auth/login', [
            'email' => 'admin@flowsync.test',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('theme.mode', 'dark');

        $this->getJson('/api/theme')->assertOk()->assertJsonPath('theme.mode', 'dark');
    }

    public function test_defaults_report_the_system_scheme_when_unset(): void
    {
        $this->loginAs('admin@flowsync.test');

        $this->getJson('/api/theme')
            ->assertOk()
            ->assertJsonPath('theme.mode', config('theme.default_mode'));
    }

    public function test_saving_colors_only_keeps_the_existing_scheme(): void
    {
        $this->loginAs('admin@flowsync.test');

        $payload = array_fill_keys(array_keys(config('theme.defaults')), '#ffffff');
        $payload['mode'] = 'dark';
        $this->putJson('/api/theme', $payload)->assertOk();

        // An older client that submits only colors must not flip the scheme.
        $payload = array_fill_keys(array_keys(config('theme.defaults')), '#eeeeee');
        unset($payload['mode']);

        $this->putJson('/api/theme', $payload)
            ->assertOk()
            ->assertJsonPath('theme.mode', 'dark')
            ->assertJsonPath('theme.card_bg', '#eeeeee');
    }

    public function test_invalid_color_scheme_is_rejected(): void
    {
        $this->loginAs('admin@flowsync.test');

        $payload = array_fill_keys(array_keys(config('theme.defaults')), '#ffffff');
        $payload['mode'] = 'neon';

        $this->putJson('/api/theme', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mode');
    }
}
