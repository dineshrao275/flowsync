<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Support\AppName;
use Illuminate\Support\Facades\Cache;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class MaintenanceModeTest extends TestCase
{
    use IsolatesDatabase;

    private function maintenance(bool $on): void
    {
        PlatformSetting::set('maintenance_mode', $on ? '1' : '0');
        Cache::forget('platform.maintenance');
    }

    public function test_tenant_traffic_gets_503_while_maintenance_is_on(): void
    {
        $this->maintenance(true);

        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])
            ->assertStatus(503)->assertJsonPath('code', 'maintenance')->assertHeader('Retry-After');
        $this->getJson('/api/dashboard')->assertStatus(503);
    }

    public function test_the_status_probe_the_health_check_and_the_public_site_stay_up(): void
    {
        $this->maintenance(true);

        $this->getJson('/api/maintenance')->assertOk()->assertJsonPath('enabled', true);
        $this->getJson('/api/health')->assertOk();
        $this->get('/')->assertStatus(200);
    }

    public function test_super_admins_can_still_sign_in(): void
    {
        $this->maintenance(true);

        $this->postJson('/api/auth/login', ['email' => 'superadmin@flowsync.test', 'password' => 'password'])->assertOk();
    }

    public function test_everything_reopens_when_maintenance_is_switched_off(): void
    {
        $this->maintenance(true);
        $this->maintenance(false);

        $this->getJson('/api/maintenance')->assertJsonPath('enabled', false);
        $this->postJson('/api/auth/login', ['email' => 'admin@flowsync.test', 'password' => 'password'])->assertOk();
    }

    public function test_the_configured_app_name_reaches_config_and_the_manifest(): void
    {
        PlatformSetting::set('app_name', 'Acme Flow');
        AppName::forget();

        $this->get('/manifest.webmanifest')->assertOk()->assertJsonPath('name', 'Acme Flow');
        $this->assertSame('Acme Flow', config('app.name'));
    }
}
