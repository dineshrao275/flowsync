<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An uploaded requirements file. Private disk, generated name, owner-only; the original name is display text only. */
class AiDocument extends Model
{
    protected $fillable = ['user_id', 'project_id', 'original_name', 'mime', 'size', 'path', 'status', 'error', 'pages', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
