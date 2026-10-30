<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\CentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Public-site media library (system DB). Files live on a public disk so the
 * server-rendered marketing pages can reference them directly; the row
 * carries dimensions + alt text for SEO-friendly `<img>` output.
 */
class Media extends Model
{
    use CentralConnection;

    protected $table = 'media';

    protected $fillable = [
        'disk',
        'path',
        'original_name',
        'mime',
        'size',
        'width',
        'height',
        'alt',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function url(): string
    {
        $url = Storage::disk($this->disk)->url($this->path);

        try {
            if (request()->hasHeader('Host')) {
                $host = request()->getHost();
                $url = preg_replace('/:\/\/localhost(:|\/|$)/', "://{$host}$1", $url);
                $url = preg_replace('/:\/\/127\.0\.0\.1(:|\/|$)/', "://{$host}$1", $url);
            }
        } catch (\Throwable) {
        }

        return $url;
    }
}
