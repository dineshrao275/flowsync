<?php

namespace App\Models;

use App\Models\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlogPost extends Model
{
    use CentralConnection;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'slug',
        'category_id',
        'title',
        'excerpt',
        'body',
        'cover_media_id',
        'author_name',
        'status',
        'published_at',
        'seo_title',
        'meta_description',
        'canonical_url',
        'noindex',
        'reading_minutes',
    ];

    protected function casts(): array
    {
        return [
            'noindex' => 'boolean',
            'reading_minutes' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BlogCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
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
