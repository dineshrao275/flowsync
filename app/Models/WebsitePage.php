<?php

namespace App\Models;

use App\Models\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * CMS pages for the public marketing site (system DB). `content` is an ordered
 * array of typed blocks (hero/text/features/cta) rendered server-side by the
 * public site controllers.
 */
class WebsitePage extends Model
{
    use CentralConnection;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'slug',
        'title',
        'content',
        'status',
        'seo_title',
        'meta_description',
        'og_image',
        'sitemap_include',
        'sort_order',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'sitemap_include' => 'boolean',
            'sort_order' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function publish(): void
    {
        $this->status = self::STATUS_PUBLISHED;
        $this->published_at = now();
        $this->save();
    }

    public function unpublish(): void
    {
        $this->status = self::STATUS_DRAFT;
        $this->save();
    }
}
