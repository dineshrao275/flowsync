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
            'slug' => 'guides',
            'name' => 'Guides',
        ])->assertStatus(201)->assertJsonPath('category.slug', 'guides');

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
