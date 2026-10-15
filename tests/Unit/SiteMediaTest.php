<?php

namespace Tests\Unit;

use App\Support\SiteMedia;
use PHPUnit\Framework\TestCase;

class SiteMediaTest extends TestCase
{
    public function test_video_urls_become_embeds_only_for_known_hosts(): void
    {
        $this->assertSame(
            'https://www.youtube.com/embed/abc123XYZ_-',
            SiteMedia::videoEmbedUrl('https://www.youtube.com/watch?v=abc123XYZ_-')
        );
        $this->assertSame(
            'https://www.youtube.com/embed/abc123XYZ_-',
            SiteMedia::videoEmbedUrl('https://youtu.be/abc123XYZ_-')
        );
        $this->assertSame(
            'https://player.vimeo.com/video/123456789',
            SiteMedia::videoEmbedUrl('https://vimeo.com/123456789')
        );
        $this->assertNull(SiteMedia::videoEmbedUrl('https://evil.example/video.mp4'));
        $this->assertNull(SiteMedia::videoEmbedUrl('https://vimeo.com/not-a-number'));
        $this->assertNull(SiteMedia::videoEmbedUrl(null));
        $this->assertNull(SiteMedia::videoEmbedUrl(''));
    }

    public function test_media_ids_are_collected_from_blocks_and_entries(): void
    {
        $ids = SiteMedia::idsForContent([
            ['type' => 'split', 'media_id' => 7],
            ['type' => 'testimonials', 'items' => [
                ['quote' => 'x', 'media_id' => 9],
                ['quote' => 'y', 'media_id' => 7],
            ]],
            ['type' => 'team', 'members' => [['name' => 'Zed']]],
            ['type' => 'text', 'body' => 'no ids here'],
            'not-an-array',
        ]);

        $this->assertSame([7, 9], $ids);
        $this->assertSame([], SiteMedia::idsForContent(null));
    }
}
