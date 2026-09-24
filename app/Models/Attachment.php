<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Attachment extends Model
{
    protected $fillable = [
        'task_id',
        'user_id',
        'stored_name',
        'original_name',
        'mime',
        'size',
        'disk',
        'path',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function url(bool $temporary = true): ?string
    {
        if ($temporary) {
            return Storage::disk($this->disk)->temporaryUrl($this->path, now()->addMinutes(30));
        }

        return Storage::disk($this->disk)->url($this->path);
    }
}
