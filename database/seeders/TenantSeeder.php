<?php

namespace Database\Seeders;

use App\Models\PlatformSetting;
use App\Models\Role;
use App\Models\SystemUser;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebsitePage;
use App\Services\TenantLifecycle;
use App\Support\TenantDatabaseManager;
use App\Support\TenantProvisioner;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    public function run(
        TenantDatabaseManager $dbm,
        TenantProvisioner $provisioner,
        TenantLifecycle $lifecycle,
    ): void {
        $dbm->connectSystem();

        SystemUser::firstOrCreate(
            ['email' => 'superadmin@flowsync.test'],
            ['name' => 'Super Admin', 'password' => 'password', 'is_super_admin' => true]
        );

        $this->call(SubscriptionPlanSeeder::class);

        collect([
            'app_name' => 'FlowSync',
            'public_registration' => config('onboarding.enabled') ? '1' : '0',
            'default_plan_id' => null,
            'maintenance_mode' => '0',
        ])->each(fn ($value, $key) => PlatformSetting::firstOrCreate(['key' => $key], ['value' => $value]));

        $this->seedWebsitePages();

        $acme = Tenant::firstOrCreate(
            ['slug' => 'acme'],
            ['name' => 'Acme Corp', 'description' => 'Primary demo tenant']
        );

        $provisioner->provisionIsolated($acme, $dbm, $lifecycle);

        $dbm->using($acme, function (): void {
            foreach ([
                ['email' => 'admin@flowsync.test', 'name' => 'Admin User', 'role' => 'admin'],
                ['email' => 'editor@flowsync.test', 'name' => 'Editor User', 'role' => 'editor'],
                ['email' => 'viewer@flowsync.test', 'name' => 'Viewer User', 'role' => 'viewer'],
            ] as $demo) {
                $user = User::firstOrCreate(
                    ['email' => $demo['email']],
                    ['name' => $demo['name'], 'password' => 'password']
                );

                $role = Role::where('slug', $demo['role'])->first();
                if ($role) {
                    $user->roles()->syncWithoutDetaching([$role->id]);
                }
            }
        });

        // Mirror the demo users added above into the central routing index.
        $provisioner->syncRouting($dbm, $acme);

        $globex = Tenant::firstOrCreate(
            ['slug' => 'globex'],
            ['name' => 'Globex', 'description' => 'Second demo tenant']
        );

        $provisioner->provisionIsolated($globex, $dbm, $lifecycle);
    }

    private function seedWebsitePages(): void
    {
        $pages = [
            [
                'slug' => 'home',
                'title' => 'FlowSync',
                'status' => WebsitePage::STATUS_PUBLISHED,
                'seo_title' => 'FlowSync — project management in motion',
                'meta_description' => 'Plan, track, and ship work across teams with multi-tenant FlowSync.',
                'sitemap_include' => true,
                'sort_order' => 1,
                'published_at' => now(),
                'content' => [
                    ['type' => 'hero', 'heading' => 'Keep your whole team in motion.',
                        'subtext' => 'FlowSync pairs workflows, time tracking, and realtime boards in one calm workspace.',
                        'cta' => ['label' => 'Start free', 'href' => '/app/register']],
                    ['type' => 'features', 'items' => [
                        ['title' => 'Realtime boards', 'text' => 'Drag tasks between columns and watch projects update live.'],
                        ['title' => 'Time that adds up', 'text' => 'Track work, compare against estimates, and see where the week went.'],
                        ['title' => 'Search across everything', 'text' => 'Jump to any task, project, or workspace in a keystroke.'],
                    ]],
                    ['type' => 'cta', 'heading' => 'Ready to start?',
                        'text' => 'Spin up a tenant in minutes — no card required.',
                        'cta' => ['label' => 'Create your workspace', 'href' => '/app/register']],
                ],
            ],
            [
                'slug' => 'about',
                'title' => 'About',
                'status' => WebsitePage::STATUS_DRAFT,
                'seo_title' => 'About FlowSync',
                'meta_description' => 'The people behind FlowSync.',
                'sitemap_include' => true,
                'sort_order' => 10,
                'content' => [['type' => 'text', 'heading' => 'About', 'body' => 'FlowSync is built for teams that stay in motion.']],
            ],
            [
                'slug' => 'privacy',
                'title' => 'Privacy Policy',
                'status' => WebsitePage::STATUS_PUBLISHED,
                'seo_title' => 'Privacy Policy',
                'meta_description' => 'How FlowSync handles your data.',
                'sitemap_include' => false,
                'sort_order' => 20,
                'published_at' => now(),
                'content' => [['type' => 'text', 'heading' => 'Privacy', 'body' => 'We keep your data in your own database. That is a promise.']],
            ],
            [
                'slug' => 'terms',
                'title' => 'Terms of Service',
                'status' => WebsitePage::STATUS_PUBLISHED,
                'seo_title' => 'Terms of Service',
                'meta_description' => 'The rules for using FlowSync.',
                'sitemap_include' => false,
                'sort_order' => 30,
                'published_at' => now(),
                'content' => [['type' => 'text', 'heading' => 'Terms', 'body' => 'Use FlowSync responsibly. Recurring billing follows your plan.']],
            ],
        ];

        foreach ($pages as $page) {
            WebsitePage::updateOrCreate(
                ['slug' => $page['slug']],
                $page
            );
        }
    }
}
