<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PlatformSetting;
use App\Models\SystemUser;
use App\Support\SiteShowcase;
use App\Support\TenantDatabaseManager;
use Database\Seeders\Concerns\SeedsPublicSite;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * First-boot data for a real deployment: platform settings, subscription plans, the public site
 * content and exactly one super admin whose credentials come from the environment. Unlike
 * DatabaseSeeder it creates no demo tenants and no accounts with a known password.
 *
 * Idempotent: every row is `firstOrCreate`, so re-running never resets an edited setting or the
 * admin's password.
 */
class ProductionSeeder extends Seeder
{
    use SeedsPublicSite;

    public function run(): void
    {
        $email = (string) env('SUPERADMIN_EMAIL', '');
        $password = (string) env('SUPERADMIN_PASSWORD', '');

        if ($email === '' || strlen($password) < 12) {
            throw new RuntimeException('Set SUPERADMIN_EMAIL and a SUPERADMIN_PASSWORD of at least 12 characters before the first boot.');
        }

        app(TenantDatabaseManager::class)->connectSystem();

        SystemUser::firstOrCreate(
            ['email' => mb_strtolower($email)],
            ['name' => 'Platform Admin', 'password' => $password, 'is_super_admin' => true],
        );

        $this->call(SubscriptionPlanSeeder::class);

        collect([
            'app_name' => (string) config('app.name', 'FlowSync'),
            'public_registration' => '0',
            'default_plan_id' => null,
            'maintenance_mode' => '0',
        ])->each(fn ($value, $key) => PlatformSetting::firstOrCreate(['key' => $key], ['value' => $value]));

        $this->seedWebsitePages();
        SiteShowcase::install();
        $this->seedWebsiteConfig();
        $this->seedBlogContent();
    }
}
