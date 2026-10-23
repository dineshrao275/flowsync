<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Media;

/**
 * Bulk-loads the Media rows a page's blocks reference so the renderer never
 * N+1s. Blocks store `media_id`; the view reads `$mediaMap[$id] ?? null`.
 */
final class SiteMedia
{
    /** Block fields that may hold a media id. */
    private const FIELDS = ['media_id'];

    /**
     * @return list<int>
     */
    public static function idsForContent(?array $content): array
    {
        $ids = [];

        foreach ($content ?? [] as $block) {
            if (! is_array($block)) {
                continue;
            }

            foreach (self::FIELDS as $field) {
                if (! empty($block[$field]) && is_numeric($block[$field])) {
                    $ids[] = (int) $block[$field];
                }
            }

            foreach (['items', 'logos', 'members'] as $list) {
                foreach ($block[$list] ?? [] as $entry) {
                    if (is_array($entry) && ! empty($entry['media_id']) && is_numeric($entry['media_id'])) {
                        $ids[] = (int) $entry['media_id'];
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * YouTube/Vimeo watch URLs become embed URLs; anything else returns null
     * so the renderer falls back to a plain link (no arbitrary iframes).
     */
    public static function videoEmbedUrl(?string $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);

        if (in_array($host, ['youtube.com', 'youtu.be'], true)) {
            if ($host === 'youtu.be') {
                $id = trim((string) parse_url($url, PHP_URL_PATH), '/');
            } else {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $id = $query['v'] ?? trim((string) parse_url($url, PHP_URL_PATH), '/');
                $id = preg_replace('#^(embed|shorts)/#', '', (string) $id);
            }

            return $id !== '' ? 'https://www.youtube.com/embed/'.rawurlencode($id) : null;
        }

        if (in_array($host, ['vimeo.com', 'player.vimeo.com'], true)) {
            $id = trim((string) parse_url($url, PHP_URL_PATH), '/');
            $id = explode('/', $id)[0];

            return ctype_digit($id) ? 'https://player.vimeo.com/video/'.$id : null;
        }

        return null;
    }

    /**
     * @return array<int, Media>
     */
    public static function mapForContent(?array $content): array
    {
        $ids = self::idsForContent($content);

        if ($ids === []) {
            return [];
        }

        return Media::whereIn('id', $ids)->get()->keyBy('id')->all();
    }
}
