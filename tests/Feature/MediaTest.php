<?php

namespace Tests\Feature;

use App\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\IsolatesDatabase;
use Tests\TestCase;

class MediaTest extends TestCase
{
    use IsolatesDatabase;

    private function loginSuperAdmin(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'superadmin@flowsync.test',
            'password' => 'password',
        ])->assertOk();
    }

    public function test_super_admin_manages_media(): void
    {
        Storage::fake('public');
        $this->loginSuperAdmin();

        $this->getJson('/api/system/media')
            ->assertOk()
            ->assertJsonPath('pagination.total', 0);

        $created = $this->postJson('/api/system/media', [
            'file' => UploadedFile::fake()->image('hero.png', 1200, 800),
            'alt' => 'Team planning on a board',
        ])->assertStatus(201)
            ->assertJsonPath('media.original_name', 'hero.png')
            ->assertJsonPath('media.alt', 'Team planning on a board')
            ->assertJsonPath('media.width', 1200)
            ->assertJsonPath('media.height', 800);

        $id = $created->json('media.id');
        $this->assertStringStartsWith('/storage/media/', $created->json('media.url'));
        Storage::disk('public')->assertExists(Media::find($id)->path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cms.media_uploaded']);

        // Search matches the original name.
        $this->getJson('/api/system/media?q=hero')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);

        $this->getJson('/api/system/media?q=nope')
            ->assertOk()
            ->assertJsonPath('pagination.total', 0);

        // Alt text is editable for SEO.
        $this->putJson("/api/system/media/{$id}", ['alt' => 'New alt'])
            ->assertOk()
            ->assertJsonPath('media.alt', 'New alt');

        $path = Media::find($id)->path;
        $this->deleteJson("/api/system/media/{$id}")->assertOk();
        $this->assertDatabaseMissing('media', ['id' => $id]);
        Storage::disk('public')->assertMissing($path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cms.media_deleted']);
    }

    public function test_media_upload_validation(): void
    {
        Storage::fake('public');
        $this->loginSuperAdmin();

        // SVGs can carry scripts — raster images only.
        $this->postJson('/api/system/media', [
            'file' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'),
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        // Plain files and oversized uploads are refused.
        $this->postJson('/api/system/media', [
            'file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        $this->postJson('/api/system/media', [
            'file' => UploadedFile::fake()->create('huge.png', 6000, 'image/png'),
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertSame(0, Media::count());
    }
}
