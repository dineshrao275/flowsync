<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserSettings;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class LocaleResolutionTest extends TestCase
{
    use IsolatesDatabase;

    private function loginAs(string $email): void
    {
        $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => 'password',
        ])->assertOk();

        $this->connectTenant('acme');
    }

    private function acme(): Tenant
    {
        return Tenant::query()->where('slug', 'acme')->firstOrFail();
    }

    private function setUserLocale(User $user, ?string $locale): void
    {
        $settings = UserSettings::firstOrCreate(['user_id' => $user->id]);
        $settings->update(['settings' => array_merge($settings->settings ?? [], ['locale' => $locale])]);
    }

    public function test_language_catalog_is_public_and_lists_completed_only(): void
    {
        $this->getJson('/api/locales')
            ->assertOk()
            ->assertJsonPath('default', 'en')
            ->assertJsonPath('locales.en.native', 'English')
            ->assertJsonPath('locales.es.native', 'Español');

        // A locale still under translation stays out of the catalog.
        config(['locales.supported.fr' => ['name' => 'French', 'native' => 'Français', 'dir' => 'ltr', 'intl' => 'fr-FR', 'complete' => false]]);

        $this->getJson('/api/locales')->assertOk()->assertJsonMissingPath('locales.fr');
    }

    public function test_guest_login_failure_defaults_to_english(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'admin@flowsync.test',
            'password' => 'wrong',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'These credentials do not match our records.')
            ->assertHeader('Content-Language', 'en');
    }

    public function test_guest_login_failure_honors_accept_language(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'admin@flowsync.test',
            'password' => 'wrong',
        ], ['Accept-Language' => 'es'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Estas credenciales no coinciden con nuestros registros.')
            ->assertHeader('Content-Language', 'es');
    }

    public function test_explicit_query_param_wins_and_unknown_falls_back(): void
    {
        // Unknown keys are skipped as if absent: with no remembered choice,
        // the chain terminates on the default.
        $this->postJson('/api/auth/login?locale=xx', [
            'email' => 'admin@flowsync.test',
            'password' => 'wrong',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'These credentials do not match our records.');

        $this->postJson('/api/auth/login?locale=es', [
            'email' => 'admin@flowsync.test',
            'password' => 'wrong',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Estas credenciales no coinciden con nuestros registros.');
    }

    public function test_explicit_choice_persists_in_session(): void
    {
        $this->postJson('/api/auth/login?locale=es', [
            'email' => 'admin@flowsync.test',
            'password' => 'wrong',
        ])->assertStatus(422);

        // No header, no param on the follow-up: the remembered session choice still applies.
        $this->postJson('/api/auth/login', [
            'email' => 'admin@flowsync.test',
            'password' => 'wrong',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Estas credenciales no coinciden con nuestros registros.');
    }

    public function test_user_preference_beats_tenant_default(): void
    {
        // Written BEFORE sign-in: the session guard memoizes its user across
        // requests inside one PHP process (fresh container per request in
        // production), so a row written after login would be invisible to a
        // lazily-read relation on the memoized instance.
        $this->connectTenant('acme');
        $user = User::where('email', 'admin@flowsync.test')->firstOrFail();
        $this->setUserLocale($user, 'es');
        $this->acme()->update(['locale' => 'en']);

        $this->postJson('/api/auth/login', [
            'email' => 'admin@flowsync.test',
            'password' => 'password',
        ])->assertOk();
        $this->connectTenant('acme');

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.locale', 'es')
            ->assertHeader('Content-Language', 'es');
    }

    public function test_tenant_default_applies_without_a_user_preference(): void
    {
        $this->acme()->update(['locale' => 'es']);
        $this->loginAs('admin@flowsync.test');

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.locale', 'es')
            ->assertHeader('Content-Language', 'es');
    }

    public function test_put_language_persists_and_rejects_unknown_or_incomplete(): void
    {
        $this->loginAs('admin@flowsync.test');

        $this->putJson('/api/language', ['locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('locale', 'en');

        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.locale', 'en');

        // Unknown keys 422…
        $this->putJson('/api/language', ['locale' => 'xx'])->assertStatus(422)->assertJsonValidationErrors('locale');

        // …and so does a locale whose dictionary is still under translation.
        config(['locales.supported.fr' => ['name' => 'French', 'native' => 'Français', 'dir' => 'ltr', 'intl' => 'fr-FR', 'complete' => false]]);
        $this->putJson('/api/language', ['locale' => 'fr'])->assertStatus(422)->assertJsonValidationErrors('locale');

        // Spanish is complete, so it persists.
        $this->putJson('/api/language', ['locale' => 'es'])->assertOk()->assertJsonPath('locale', 'es');
    }

    public function test_platform_super_admin_preference_uses_central_settings(): void
    {
        $login = $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();

        $this->putJson('/api/language', ['locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('locale', 'en');

        $this->assertSame('en', PlatformSetting::value('locale.user.'.$login->json('user.id')));

        $this->getJson('/api/auth/me')->assertOk()->assertHeader('Content-Language', 'en');
    }
}
