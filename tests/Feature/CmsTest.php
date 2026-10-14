<?php

namespace Tests\Feature;

use App\Models\WebsitePage;
use App\Models\WebsiteRedirect;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class CmsTest extends TestCase
{
    use IsolatesDatabase;

    private function loginSuperAdmin(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();
    }

    public function test_super_admin_manages_cms_pages(): void
    {
        $this->loginSuperAdmin();

        $this->getJson('/api/system/pages')
            ->assertOk()
            ->assertJsonPath('pages.0.slug', 'home')
            ->assertJsonCount(4, 'pages');

        $created = $this->postJson('/api/system/pages', [
            'slug' => 'changelog',
            'title' => 'Changelog',
            'status' => 'draft',
            'content' => [['type' => 'text', 'heading' => 'Changelog', 'body' => 'What changed.']],
            'sitemap_include' => true,
            'sort_order' => 5,
        ])->assertStatus(201)
            ->assertJsonPath('page.slug', 'changelog')
            ->assertJsonPath('page.status', 'draft')
            ->assertJsonPath('page.published_at', null);

        $id = $created->json('page.id');

        $this->putJson("/api/system/pages/{$id}", [
            'slug' => 'changelog',
            'title' => 'Product Updates',
            'status' => 'draft',
            'content' => [['type' => 'text', 'heading' => 'Product Updates', 'body' => 'What shipped.']],
        ])->assertOk()->assertJsonPath('page.title', 'Product Updates');

        $this->postJson("/api/system/pages/{$id}/publish")
            ->assertOk()
            ->assertJsonPath('page.status', 'published');
        $this->assertNotNull(WebsitePage::find($id)->published_at);

        $this->assertDatabaseHas('audit_logs', ['action' => 'cms.page_published']);

        $this->postJson("/api/system/pages/{$id}/unpublish")
            ->assertOk()
            ->assertJsonPath('page.status', 'draft');

        $this->assertDatabaseHas('audit_logs', ['action' => 'cms.page_updated']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cms.page_created']);

        $this->deleteJson("/api/system/pages/{$id}")->assertOk();
        $this->assertDatabaseMissing('website_pages', ['id' => $id]);
    }

    public function test_the_home_page_is_protected(): void
    {
        $this->loginSuperAdmin();

        $homeId = WebsitePage::where('slug', 'home')->value('id');

        $this->deleteJson("/api/system/pages/{$homeId}")->assertStatus(422);
    }

    public function test_public_pages_are_served_from_the_database(): void
    {
        // Seeded home renders on the root with its hero block.
        $this->get('/')
            ->assertOk()
            ->assertSee('Keep your whole team in motion.')
            ->assertSee('FlowSync');

        $this->get('/privacy')
            ->assertOk()
            ->assertSee('Privacy')
            ->assertSee('We keep your data in your own database.');

        $this->get('/about')->assertNotFound();
        $this->get('/does-not-exist')->assertNotFound();
    }

    public function test_pages_live_at_top_level_slugs_with_legacy_redirects(): void
    {
        // Canonical URLs are top-level; the legacy prefix 301s to them.
        $this->get('/page/privacy')
            ->assertRedirect('/privacy');

        $this->get('/page/home')->assertRedirect('/');

        $this->get('/page/does-not-exist')->assertNotFound();

        // Reserved slugs never become pages, and the SPA keeps its routes.
        $this->get('/app/login')
            ->assertOk()
            ->assertSee('id="root"', false);
    }

    public function test_reserved_slugs_are_rejected_by_the_cms(): void
    {
        $this->loginSuperAdmin();

        $this->postJson('/api/system/pages', [
            'slug' => 'app',
            'title' => 'App',
            'status' => 'draft',
        ])->assertStatus(422)->assertJsonValidationErrors('slug');
    }

    public function test_renaming_a_page_creates_a_redirect_from_the_old_url(): void
    {
        $this->loginSuperAdmin();

        $created = $this->postJson('/api/system/pages', [
            'slug' => 'old-pricing',
            'title' => 'Pricing',
            'status' => 'published',
            'content' => [['type' => 'text', 'heading' => 'Pricing', 'body' => 'Plans.']],
        ])->assertStatus(201);

        $id = $created->json('page.id');

        $this->putJson("/api/system/pages/{$id}", [
            'slug' => 'pricing',
            'title' => 'Pricing',
            'status' => 'published',
        ])->assertOk();

        $this->assertDatabaseHas('website_redirects', [
            'from_path' => 'old-pricing',
            'to_path' => '/pricing',
            'status_code' => 301,
        ]);

        // The old URL 301s to the new one and records the hit.
        $this->get('/old-pricing')->assertRedirect('/pricing');
        $this->get('/old-pricing')->assertRedirect('/pricing');
        $this->assertSame(2, WebsiteRedirect::where('from_path', 'old-pricing')->value('hits'));

        $this->get('/pricing')
            ->assertOk()
            ->assertSee('Pricing');
    }

    public function test_super_admin_manages_redirects(): void
    {
        $this->loginSuperAdmin();

        $created = $this->postJson('/api/system/redirects', [
            'from_path' => '/legacy-tour',
            'to_path' => '/features',
        ])->assertStatus(201)
            ->assertJsonPath('redirect.from_path', 'legacy-tour')
            ->assertJsonPath('redirect.status_code', 301);

        $id = $created->json('redirect.id');

        $this->getJson('/api/system/redirects')
            ->assertOk()
            ->assertJsonPath('redirects.0.from_path', 'legacy-tour');

        $this->get('/legacy-tour')->assertRedirect('/features');

        $this->deleteJson("/api/system/redirects/{$id}")->assertOk();
        $this->assertDatabaseMissing('website_redirects', ['id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cms.redirect_created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cms.redirect_deleted']);
    }

    public function test_api_unknown_paths_keep_their_json_404(): void
    {
        $this->getJson('/api/definitely-not-a-route')->assertNotFound();
    }

    public function test_public_layout_has_skip_link_mobile_menu_and_footer(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Skip to content', false)
            ->assertSee('id="site-menu-button"', false)
            ->assertSee('id="site-mobile-menu"', false)
            ->assertSee('id="main-content"', false)
            ->assertSee('<footer', false)
            ->assertSee('/sitemap.xml');
    }

    public function test_public_pages_carry_seo_head_and_structured_data(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="http://localhost"', false)
            ->assertSee('<meta name="robots" content="index, follow">', false)
            ->assertSee('<meta name="theme-color" content="#4f46eb">', false)
            ->assertSee('<link rel="icon" type="image/svg+xml"', false)
            ->assertSee('<meta property="og:site_name" content="FlowSync">', false)
            ->assertSee('<meta property="og:type" content="website">', false)
            ->assertSee('<meta property="og:locale" content="en_US">', false)
            ->assertSee('<meta name="twitter:card"', false)
            ->assertSee('"@type":"Organization"', false)
            ->assertSee('"@type":"WebSite"', false)
            ->assertDontSee('"@type":"BreadcrumbList"', false);

        // Non-home pages get a breadcrumb trail for Google rich results.
        $this->get('/privacy')
            ->assertOk()
            ->assertSee('"@type":"BreadcrumbList"', false)
            ->assertSee('Privacy');
    }

    public function test_unknown_public_pages_render_the_designed_404(): void
    {
        $this->get('/page/does-not-exist')
            ->assertNotFound()
            ->assertSee('wandered off the board')
            ->assertSee('Back to home');
    }

    public function test_sitemap_and_robots_are_derived_from_cms_pages(): void
    {
        WebsitePage::create([
            'slug' => 'docs',
            'title' => 'Docs',
            'status' => WebsitePage::STATUS_PUBLISHED,
            'content' => [['type' => 'text', 'heading' => 'Docs', 'body' => 'How to use FlowSync.']],
            'sitemap_include' => true,
            'published_at' => now(),
        ]);
        WebsitePage::create([
            'slug' => 'internal',
            'title' => 'Internal',
            'status' => WebsitePage::STATUS_PUBLISHED,
            'content' => [['type' => 'text', 'heading' => 'Internal', 'body' => 'Internal only.']],
            'sitemap_include' => false,
            'published_at' => now(),
        ]);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml')
            ->assertSee('<loc>http://localhost/docs</loc>', false)
            ->assertDontSee('/internal')
            ->assertDontSee('/page/privacy')
            ->assertSee('<lastmod>', false);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertSee('Sitemap:')
            ->assertSee('/sitemap.xml');
    }
}
