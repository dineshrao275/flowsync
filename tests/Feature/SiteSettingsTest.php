<?php

namespace Tests\Feature;

use App\Models\Media;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class SiteSettingsTest extends TestCase
{
    use IsolatesDatabase;

    private function loginSuperAdmin(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();
    }

    public function test_super_admin_reads_and_updates_site_settings(): void
    {
        $this->loginSuperAdmin();

        // The seeder ships a full nav/tagline, so assert shape — not seed copy.
        $settings = $this->getJson('/api/system/website-settings')->assertOk()->json('settings');
        $this->assertIsString($settings['tagline']);
        $this->assertNotEmpty($settings['nav']);
        foreach ($settings['nav'] as $link) {
            $this->assertNotEmpty($link['label']);
            $this->assertNotEmpty($link['href']);
        }
        $this->assertNull($settings['ga_measurement_id']);

        $this->putJson('/api/system/website-settings', [
            'tagline' => 'Ship work worldwide.',
            'nav' => [
                ['label' => 'Home', 'href' => '/'],
                ['label' => 'Pricing', 'href' => '/pricing'],
            ],
            'announcement' => 'New: HRMS is here',
            'announcement_href' => '/about',
            'ga_measurement_id' => 'G-ABC123XYZ',
            'contact_email' => 'hello@flowsync.test',
        ])->assertOk()
            ->assertJsonPath('settings.tagline', 'Ship work worldwide.')
            ->assertJsonPath('settings.nav.1.href', '/pricing');

        $this->assertDatabaseHas('platform_settings', ['key' => 'website.tagline', 'value' => 'Ship work worldwide.']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cms.site_settings_updated']);
    }

    public function test_site_settings_validation(): void
    {
        $this->loginSuperAdmin();

        $this->putJson('/api/system/website-settings', [
            'nav' => [['label' => 'Evil', 'href' => 'javascript:alert(1)']],
        ])->assertStatus(422)->assertJsonValidationErrors('nav.0.href');

        $this->putJson('/api/system/website-settings', [
            'ga_measurement_id' => 'not-a-ga-id',
        ])->assertStatus(422)->assertJsonValidationErrors('ga_measurement_id');

        $this->putJson('/api/system/website-settings', [
            'logo_media_id' => 999999,
        ])->assertStatus(422)->assertJsonValidationErrors('logo_media_id');

        $this->putJson('/api/system/website-settings', [
            'contact_email' => 'not-an-email',
        ])->assertStatus(422)->assertJsonValidationErrors('contact_email');
    }

    public function test_public_layout_consumes_site_settings(): void
    {
        $this->loginSuperAdmin();

        $media = Media::create([
            'disk' => 'public',
            'path' => 'media/logo.png',
            'original_name' => 'logo.png',
            'mime' => 'image/png',
            'size' => 1234,
            'alt' => 'FlowSync logo',
        ]);

        $this->putJson('/api/system/website-settings', [
            'tagline' => 'Ship work worldwide.',
            'logo_media_id' => $media->id,
            'nav' => [['label' => 'Home', 'href' => '/'], ['label' => 'Pricing', 'href' => '/pricing']],
            'announcement' => 'New: HRMS is here',
            'ga_measurement_id' => 'G-ABC123XYZ',
            'gsc_verification' => 'verify-token',
            'custom_head' => '<meta name="custom" content="head">',
            'social' => [['network' => 'X', 'url' => 'https://x.example/flowsync']],
        ])->assertOk();

        $this->get('/')
            ->assertOk()
            ->assertSee('Ship work worldwide.', false)
            ->assertSee('storage/media/logo.png', false)
            ->assertSee('href="/pricing"', false)
            ->assertSee('New: HRMS is here')
            ->assertSee('googletagmanager.com/gtag/js?id=G-ABC123XYZ', false)
            ->assertSee('<meta name="google-site-verification" content="verify-token">', false)
            ->assertSee('<meta name="custom" content="head">', false)
            ->assertSee('https://x.example/flowsync');
    }
}
