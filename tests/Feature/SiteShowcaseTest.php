<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\WebsitePage;
use App\Support\SiteShowcase;
use Illuminate\Support\Facades\Storage;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** The "See it in action" product tour: real screenshots imported into the media library and rendered as tabs. */
class SiteShowcaseTest extends TestCase
{
    use IsolatesDatabase;

    public function test_install_imports_the_screenshots_and_adds_the_tour_once(): void
    {
        Storage::fake('public');

        $this->assertGreaterThan(0, SiteShowcase::install());

        $home = WebsitePage::where('slug', 'home')->firstOrFail();
        $tour = collect($home->content)->firstWhere('type', 'showcase');
        $this->assertNotNull($tour);
        $this->assertCount(count(SiteShowcase::SHOTS), $tour['items']);
        foreach ($tour['items'] as $item) {
            $media = Media::findOrFail($item['media_id']);
            Storage::disk('public')->assertExists($media->path);
            $this->assertNotEmpty($media->alt);
        }
        $this->assertNotEmpty(collect($home->content)->firstWhere('type', 'hero')['media_id']);

        // Idempotent: a second run changes nothing and never duplicates the block.
        $this->assertSame(0, SiteShowcase::install());
        $this->assertCount(1, collect(WebsitePage::where('slug', 'home')->first()->content)->where('type', 'showcase'));
    }

    public function test_an_editor_who_changed_the_tour_is_left_alone(): void
    {
        Storage::fake('public');
        SiteShowcase::install();
        $home = WebsitePage::where('slug', 'home')->firstOrFail();
        $content = collect($home->content)->map(fn ($b) => ($b['type'] ?? '') === 'showcase' ? [...$b, 'heading' => 'Our tour'] : $b)->all();
        $home->update(['content' => $content]);

        SiteShowcase::install();

        $this->assertSame('Our tour', collect(WebsitePage::where('slug', 'home')->first()->content)->firstWhere('type', 'showcase')['heading']);
    }

    public function test_the_public_page_renders_the_tour_as_accessible_tabs(): void
    {
        Storage::fake('public');
        SiteShowcase::install();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('role="tablist"', $html);
        $this->assertSame(count(SiteShowcase::SHOTS), substr_count($html, 'data-showcase-tab='));
        $this->assertStringContainsString('role="tabpanel"', $html);
        $this->assertStringContainsString('alt="Kanban board', str_replace('alt="Kanban board with', 'alt="Kanban board', $html));
    }
}
