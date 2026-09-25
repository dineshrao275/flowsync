<?php

namespace Tests\Feature;

use App\Models\WebsitePage;
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

        $this->get('/page/privacy')
            ->assertOk()
            ->assertSee('Privacy')
            ->assertSee('We keep your data in your own database.');

        $this->get('/page/about')->assertNotFound();
        $this->get('/page/does-not-exist')->assertNotFound();
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
            ->assertSee('/page/docs')
            ->assertDontSee('/page/internal')
            ->assertDontSee('/page/privacy');

        $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertSee('Sitemap:')
            ->assertSee('/sitemap.xml');
    }
}
