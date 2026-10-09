<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One API call made with a token: who, what, how it ended. No bodies are stored. */
class IntegrationLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['token_id', 'user_id', 'method', 'path', 'status', 'duration_ms', 'ip', 'request_id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
