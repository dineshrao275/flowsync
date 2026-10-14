<?php

namespace Tests\Feature;

use App\Models\Media;
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

    public function test_pricing_faq_and_comparison_blocks_render(): void
    {
        $this->loginSuperAdmin();

        $this->postJson('/api/system/pages', [
            'slug' => 'pricing',
            'title' => 'Pricing',
            'status' => 'published',
            'content' => [
                ['type' => 'pricing', 'eyebrow' => 'Plans', 'heading' => 'Simple pricing'],
                ['type' => 'faq', 'heading' => 'Questions', 'items' => [
                    ['q' => 'Is there a free trial?', 'a' => 'Yes — fourteen days, no card.'],
                    ['q' => 'Empty answer', 'a' => ''],
                ]],
                ['type' => 'comparison', 'heading' => 'Compare', 'columns' => [
                    ['heading' => 'Starter'], ['heading' => 'Pro'],
                ], 'rows' => [
                    ['label' => 'Time tracking', 'values' => ['yes', 'yes']],
                    ['label' => 'API access', 'values' => ['no', 'yes']],
                    ['label' => 'Support', 'values' => ['Email', 'Priority email']],
                ]],
            ],
        ])->assertStatus(201);

        $this->get('/pricing')
            ->assertOk()
            // Live plans: seeded catalog names, real prices, module labels.
            ->assertSee('Starter')
            ->assertSee('Most popular')
            ->assertSee('Time Tracking')
            // FAQ accordion + FAQPage rich result (unanswered items excluded).
            ->assertSee('Is there a free trial?')
            ->assertSee('<details', false)
            ->assertSee('"@type":"FAQPage"', false)
            ->assertSee('fourteen days, no card.')
            ->assertDontSee('"name":"Empty answer"', false)
            // Comparison table cells.
            ->assertSee('Compare')
            ->assertSee('aria-label="Included"', false)
            ->assertSee('Priority email');

        // Product filter narrows to that product's plans.
        $page = WebsitePage::where('slug', 'pricing')->first();
        $content = $page->content;
        $content[0] = ['type' => 'pricing', 'heading' => 'TMS plans', 'product' => 'tms'];
        $page->update(['content' => $content]);

        $this->get('/pricing')
            ->assertOk()
            ->assertSee('TMS Starter');
    }

    public function test_display_blocks_render_with_media(): void
    {
        $this->loginSuperAdmin();

        $logo = Media::create(['disk' => 'public', 'path' => 'media/acme.png', 'original_name' => 'acme.png', 'mime' => 'image/png', 'size' => 100, 'alt' => 'Acme logo']);
        $photo = Media::create(['disk' => 'public', 'path' => 'media/ada.png', 'original_name' => 'ada.png', 'mime' => 'image/png', 'size' => 100, 'width' => 200, 'height' => 200]);

        $this->postJson('/api/system/pages', [
            'slug' => 'showcase',
            'title' => 'Showcase',
            'status' => 'published',
            'content' => [
                ['type' => 'logo_cloud', 'heading' => 'Loved by teams at', 'logos' => [
                    ['name' => 'Acme Corp', 'media_id' => $logo->id],
                    ['name' => 'Globex'],
                ]],
                ['type' => 'stats', 'items' => [
                    ['value' => '99.99%', 'label' => 'Uptime'],
                    ['value' => '40+', 'label' => 'Countries'],
                ]],
                ['type' => 'steps', 'eyebrow' => 'How it works', 'heading' => 'Live in three steps', 'items' => [
                    ['title' => 'Sign up', 'text' => 'Create your workspace.'],
                ]],
                ['type' => 'split', 'eyebrow' => 'Boards', 'heading' => 'See everything', 'body' => 'One calm view.', 'media_id' => $photo->id, 'flip' => true, 'cta' => ['label' => 'Try it', 'href' => '/app/register']],
                ['type' => 'testimonials', 'heading' => 'Customers', 'items' => [
                    ['quote' => 'It changed how we ship.', 'name' => 'Ada', 'role' => 'CTO, Acme', 'media_id' => $photo->id],
                ]],
                ['type' => 'team', 'heading' => 'Team', 'members' => [
                    ['name' => 'Grace', 'role' => 'Founder', 'media_id' => $photo->id],
                ]],
                ['type' => 'video', 'heading' => 'Watch', 'embed_url' => 'https://www.youtube.com/watch?v=abc123XYZ_-'],
            ],
        ])->assertStatus(201);

        $this->get('/showcase')
            ->assertOk()
            ->assertSee('Loved by teams at')
            ->assertSee('storage/media/acme.png', false)
            ->assertSee('Globex')
            ->assertSee('99.99%')
            ->assertSee('Live in three steps')
            ->assertSee('Sign up')
            ->assertSee('See everything')
            ->assertSee('storage/media/ada.png', false)
            ->assertSee('It changed how we ship.')
            ->assertSee('Grace')
            ->assertSee('https://www.youtube.com/embed/abc123XYZ_-', false);

        // Unknown video hosts never become iframes.
        $page = WebsitePage::where('slug', 'showcase')->first();
        $content = $page->content;
        $content[6] = ['type' => 'video', 'heading' => 'Watch', 'embed_url' => 'https://evil.example/v.mp4'];
        $page->update(['content' => $content]);

        $this->get('/showcase')
            ->assertOk()
            ->assertDontSee('<iframe', false)
            ->assertSee('Watch the video');
    }

    public function test_super_admin_duplicates_a_page(): void
    {
        $this->loginSuperAdmin();

        $created = $this->postJson('/api/system/pages', [
            'slug' => 'tour',
            'title' => 'Tour',
            'status' => 'published',
            'content' => [['type' => 'text', 'heading' => 'Tour', 'body' => 'Look around.']],
        ])->assertStatus(201);

        $id = $created->json('page.id');

        $copy = $this->postJson("/api/system/pages/{$id}/duplicate")
            ->assertStatus(201)
            ->assertJsonPath('page.slug', 'tour-copy')
            ->assertJsonPath('page.title', 'Copy of Tour')
            ->assertJsonPath('page.status', 'draft')
            ->assertJsonPath('page.published_at', null);

        $this->assertDatabaseHas('audit_logs', ['action' => 'cms.page_duplicated']);

        // The copy starts unpublished even though the source is live.
        $this->get('/tour-copy')->assertNotFound();

        // A second duplicate keeps slugs unique.
        $this->postJson("/api/system/pages/{$id}/duplicate")
            ->assertStatus(201)
            ->assertJsonPath('page.slug', 'tour-copy-2');

        $this->deleteJson("/api/system/pages/{$copy->json('page.id')}")->assertOk();
    }

    public function test_signed_preview_renders_drafts_without_indexing(): void
    {
        $this->loginSuperAdmin();

        $created = $this->postJson('/api/system/pages', [
            'slug' => 'secret-launch',
            'title' => 'Secret Launch',
            'status' => 'draft',
            'content' => [
                ['type' => 'text', 'heading' => 'Secret', 'body' => 'Shh.'],
                ['type' => 'richtext', 'html' => '<h2>Formatted</h2><p>Rich <a href="/pricing">body</a>.</p>'],
            ],
        ])->assertStatus(201);

        $id = $created->json('page.id');

        $previewUrl = $this->getJson("/api/system/pages/{$id}")
            ->assertOk()
            ->json('page.preview_url');

        $this->assertNotNull($previewUrl);

        // Drafts never serve publicly…
        $this->get('/secret-launch')->assertNotFound();

        // …but the signed preview renders them, formatted blocks included,
        // while telling every crawler to stay away.
        $this->get($previewUrl)
            ->assertOk()
            ->assertSee('Shh.')
            ->assertSee('<h2>Formatted</h2>', false)
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        // A tampered signature is refused.
        $this->get($previewUrl.'tampered')->assertForbidden();
    }

    public function test_page_seo_overrides_shape_the_head_and_sitemap(): void
    {
        $this->loginSuperAdmin();

        $created = $this->postJson('/api/system/pages', [
            'slug' => 'campaign',
            'title' => 'Campaign',
            'status' => 'published',
            'seo_title' => 'Campaign Page',
            'meta_description' => 'Default description.',
            'canonical_url' => 'https://example.com/campaign',
            'noindex' => true,
            'og_title' => 'Social Campaign',
            'og_description' => 'Social description.',
            'content' => [['type' => 'text', 'heading' => 'Campaign', 'body' => 'Hello.']],
        ])->assertStatus(201);

        $id = $created->json('page.id');

        $this->get('/campaign')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="https://example.com/campaign">', false)
            ->assertSee('<meta name="robots" content="noindex, follow">', false)
            ->assertSee('<meta property="og:title" content="Social Campaign">', false)
            ->assertSee('<meta property="og:description" content="Social description.">', false);

        // Noindexed pages stay out of the sitemap even when published.
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertDontSee('/campaign');

        $this->putJson("/api/system/pages/{$id}", [
            'slug' => 'campaign',
            'title' => 'Campaign',
            'status' => 'published',
            'noindex' => false,
        ])->assertOk();

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('<loc>http://localhost/campaign</loc>', false);
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
