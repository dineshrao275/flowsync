<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class BlogTest extends TestCase
{
    use IsolatesDatabase;

    private function loginSuperAdmin(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();
    }

    public function test_super_admin_manages_categories_and_posts(): void
    {
        $this->loginSuperAdmin();

        $category = $this->postJson('/api/system/blog-categories', [
            'slug' => 'howtos',
            'name' => 'How-tos',
        ])->assertStatus(201)->assertJsonPath('category.slug', 'howtos');

        $categoryId = $category->json('category.id');

        $created = $this->postJson('/api/system/blog-posts', [
            'slug' => 'hello-boards',
            'category_id' => $categoryId,
            'title' => 'Hello Boards',
            'excerpt' => 'An intro.',
            'body' => '<p>'.str_repeat('word ', 400).'</p>',
            'author_name' => 'Team FlowSync',
            'status' => 'draft',
        ])->assertStatus(201)
            ->assertJsonPath('post.slug', 'hello-boards')
            ->assertJsonPath('post.reading_minutes', 2)
            ->assertJsonPath('post.published_at', null);

        $id = $created->json('post.id');

        $this->getJson("/api/system/blog-posts/{$id}")
            ->assertOk()
            ->assertJsonPath('post.title', 'Hello Boards');

        $this->putJson("/api/system/blog-posts/{$id}", [
            'slug' => 'hello-boards',
            'title' => 'Hello Boards, Updated',
            'status' => 'draft',
        ])->assertOk()->assertJsonPath('post.title', 'Hello Boards, Updated');

        $this->postJson("/api/system/blog-posts/{$id}/publish")
            ->assertOk()
            ->assertJsonPath('post.status', 'published');
        $this->assertNotNull(BlogPost::find($id)->published_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cms.post_published']);

        $this->postJson("/api/system/blog-posts/{$id}/unpublish")
            ->assertOk()
            ->assertJsonPath('post.status', 'draft');

        // A category holding posts refuses deletion.
        $this->deleteJson("/api/system/blog-categories/{$categoryId}")->assertStatus(422);

        $this->deleteJson("/api/system/blog-posts/{$id}")->assertOk();
        $this->assertDatabaseMissing('blog_posts', ['id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cms.post_deleted']);

        $this->deleteJson("/api/system/blog-categories/{$categoryId}")->assertOk();
        $this->assertDatabaseMissing('blog_categories', ['id' => $categoryId]);
    }

    public function test_public_blog_serves_published_posts(): void
    {
        $this->loginSuperAdmin();

        $categoryId = $this->postJson('/api/system/blog-categories', [
            'slug' => 'howtos', 'name' => 'How-tos',
        ])->json('category.id');

        $live = $this->postJson('/api/system/blog-posts', [
            'slug' => 'kanban-tips',
            'category_id' => $categoryId,
            'title' => 'Kanban Tips',
            'excerpt' => 'Work in progress, wisely.',
            'body' => '<h2>Limit WIP</h2><p>Less is more.</p>',
            'author_name' => 'Team FlowSync',
            'status' => 'published',
        ])->json('post.id');

        $draft = $this->postJson('/api/system/blog-posts', [
            'slug' => 'unfinished-thoughts',
            'title' => 'Unfinished Thoughts',
            'status' => 'draft',
        ])->json('post.id');

        $this->get('/blog')
            ->assertOk()
            ->assertSee('Kanban Tips')
            ->assertSee('Work in progress, wisely.')
            ->assertSee('Guides')
            ->assertDontSee('Unfinished Thoughts');

        $this->get('/blog/kanban-tips')
            ->assertOk()
            ->assertSee('<h2>Limit WIP</h2>', false)
            ->assertSee('Team FlowSync')
            ->assertSee('"@type":"BlogPosting"', false)
            ->assertSee('"@type":"BreadcrumbList"', false)
            ->assertSee('/blog/category/howtos');

        $this->get('/blog/category/howtos')
            ->assertOk()
            ->assertSee('Kanban Tips')
            ->assertDontSee('Unfinished Thoughts');

        $this->get('/blog/category/nope')->assertNotFound();
        $this->get('/blog/unfinished-thoughts')->assertNotFound();

        $this->get('/blog/rss.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/rss+xml')
            ->assertSee('<link>http://localhost/blog/kanban-tips</link>', false)
            ->assertDontSee('unfinished-thoughts');

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('<loc>http://localhost/blog/kanban-tips</loc>', false)
            ->assertSee('<loc>http://localhost/blog/category/howtos</loc>', false);

        // Noindexed posts stay out of the sitemap.
        $this->putJson("/api/system/blog-posts/{$live}", [
            'slug' => 'kanban-tips', 'title' => 'Kanban Tips', 'status' => 'published', 'noindex' => true,
        ])->assertOk();

        $this->get('/sitemap.xml')->assertOk()->assertDontSee('/blog/kanban-tips');

        // Signed preview renders the draft without indexing it.
        $previewUrl = $this->getJson("/api/system/blog-posts/{$draft}")->json('post.preview_url');
        $this->assertNotNull($previewUrl);

        $this->get($previewUrl)
            ->assertOk()
            ->assertSee('Unfinished Thoughts')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->get($previewUrl.'tampered')->assertForbidden();
    }

    public function test_blog_list_filters_and_search(): void
    {
        $this->loginSuperAdmin();

        $this->postJson('/api/system/blog-posts', [
            'slug' => 'kanban-tips', 'title' => 'Kanban Tips', 'status' => 'published',
        ])->assertStatus(201);

        $this->getJson('/api/system/blog-posts?q=kanban')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);

        $this->getJson('/api/system/blog-posts?status=draft')
            ->assertOk()
            ->assertJsonPath('pagination.total', 0);
    }
}
